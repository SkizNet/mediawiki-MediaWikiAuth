<?php

namespace MediaWikiAuth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use Status;
use User;

class ExternalWikiPrimaryAuthenticationProvider
	extends \MediaWiki\Auth\AbstractPasswordPrimaryAuthenticationProvider
{
	protected $cookieJar;
	private $userCache = [];

	public function __construct( array $params = [] ) {
		parent::__construct( $params );

		$this->cookieJar = new \CookieJar();
	}

	/**
	 * Attempt to authenticate against a remote wiki's API
	 *
	 * We first check to see if the given user exists in the remote wiki; if they do not
	 * then we abstain from this auth provider (as the username may be handled by a different
	 * provider). If they exist, we attempt to auth against that username with our provided
	 * password, and return the result (PASS/FAIL).
	 *
	 * Once the user successfully authenticates, we import their Preferences and Watchlist from
	 * the remote wiki and prompt them to change their password.
	 */
	public function beginPrimaryAuthentication( array $reqs ) {
		$req = AuthenticationRequest::getRequestByClass( $reqs, PasswordAuthenticationRequest::class );
		if ( !$req ) {
			return AuthenticationResponse::newAbstain();
		}

		if ( $req->username === null || $req->password === null ) {
			return AuthenticationResponse::newAbstain();
		}

		// Check if the user exists on the local wiki. If so, do not attempt to auth against the remote one.
		// if $existingUser is false, that means username validation failed so we won't be able to auth with
		// this name anyway once the account does exist.
		$existingUser = User::newFromName( $req->username, 'usable' );
		if ( $existingUser === false || $existingUser->getId() !== 0 ) {
			return AuthenticationResponse::newAbstain();
		}

		$username = $existingUser->getName();

		// Check for username existence on other wiki
		if ( !$this->testUserExists( $username ) ) {
			return AuthenticationResponse::newAbstain();
		}

		// Grab remote MediaWiki version; our auth flow depends on what we get back
		$resp = $this->apiRequest( 'GET', [
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'general'
		], [], __METHOD__ );
		// generator is of the form 'MediaWiki X.X.X'; strip MediaWiki from out front
		$remoteVersion = substr( $resp->query->general->generator, 10 );

		if ( version_compare( $remoteVersion, '1.27', '<' ) ) {
			// use old login API
			$resp = $this->apiRequest( 'POST', [
				'action' => 'login'
			], [
				'lgname' => $username,
				'lgpassword' => $req->password
			], __METHOD__ );

			if ( $resp->login->result === 'NeedToken' ) {
				$loginToken = $resp->login->token;

				$resp = $this->apiRequest( 'POST', [
					'action' => 'login'
				], [
					'lgname' => $username,
					'lgpassword' => $req->password,
					'lgtoken' => $loginToken
				], __METHOD__ );
			}

			if ( $resp->login->result !== 'Success' ) {
				$this->logger->info( 'Authentication against legacy remote API failed for reason ' . $resp->login->result,
					[ 'remoteVersion' => $remoteVersion, 'caller' => __METHOD__, 'username' => $username ] );
				return AuthenticationResponse::newFail( wfMessage( 'mwa-authfail' ) );
			}
		} else {
			// use new clientlogin API.
			// TODO: We do not currently support things that inject into the auth flow,
			// such as if the remote wiki uses OAuth, two-factor authentication,
			// or has CAPTCHAs on login.

			// Step 1. Grab a login token
			$resp = $this->apiRequest( 'GET', [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'login'
			], [], __METHOD__ );
			$loginToken = $resp->query->tokens->logintoken;

			$resp = $this->apiRequest( 'POST', [
				'action' => 'clientlogin'
			], [
				'loginreturnurl' => $this->config->get( 'Server' ),
				'logintoken' => $loginToken,
				'username' => $username,
				'password' => $req->password
			], __METHOD__ );

			if ( $resp->clientlogin->status !== 'PASS' ) {
				$this->logger->info( 'Authentication against modern remote API failed for reason ' . $resp->clientlogin->status,
					[ 'remoteVersion' => $remoteVersion, 'caller' => __METHOD__, 'username' => $username ] );
				return AuthenticationResponse::newFail( wfMessage( 'mwa-authfail' ) );
			}
		}

		// Remote login was successful, an account will be automatically created for the user by the system
		// Mark them as (maybe) needing to reset their password as a secondary auth step.
		if ( $this->config->get( 'MediaWikiAuthAllowPasswordChange' ) ) {
			$this->setPasswordResetFlag( $username, Status::newGood() );
		}

		return AuthenticationResponse::newPass( $username );
	}

	/**
	 * Callback for when an account is created automatically upon login (not necessarily by us).
	 *
	 * @param User $user
	 * @param string $source
	 * @return void
	 */
	public function autoCreatedAccount( $user, $source ) {
		if ( $source !== __CLASS__ ) {
			// this account wasn't created by us, so we have nothing to contribute to it
			return;
		}

		// $user->saveChanges() is called automatically after this runs,
		// so calling it ourselves is not necessary.
		// This is where we fetch user preferences and watchlist to save locally.
		$userInfo = $this->apiRequest( 'GET', [
			'action' => 'query',
			'meta' => 'userinfo',
			'uiprop' => 'blockinfo|hasmsg|editcount|groups|groupmemberships|options|email|realname|registrationdate'
		], [], __METHOD__ );

		$wrquery = [
			'action' => 'query',
			'list' => 'watchlistraw',
			'wrprop' => 'changed',
			'wrlimit' => 'max'
		];

		$watchlist = [];

		while ( true ) {
			$resp = $this->apiRequest( 'GET', $wrquery, [], __METHOD__ );
			$watchlist = array_merge( $watchlist, $resp->watchlistraw );

			if ( !isset( $resp->{'query-continue'} ) ) {
				break;
			}

			$wrquery['wrcontinue'] = $resp->{'query-continue'}->watchlistraw->wrcontinue;
		}

		// enqueue jobs to actually add the watchlist pages to the user, since there might be a lot of them
		$pagesPerJob = (int)$this->config->get( 'UpdateRowsPerJob' );
		if ( $pagesPerJob <= 0 ) {
			$this->logger->warning( '$wgUpdateRowsPerJob is set to 0 or a negative value; importing watchlist in batches of 300 instead.' );
			$pagesPerJob = 300;
		}

		$jobs = [];
		$title = $user->getUserPage(); // not used by us, but Job constructor needs a valid Title
		while ( $watchlist ) {
			// array_splice reduces the size of $watchlist and returns the removed elements.
			// This avoids memory bloat so that we only keep the watchlist resident in memory one time.
			$slice = array_splice( $watchlist, 0, $pagesPerJob );
			$jobs[] = new PopulateImportedWatchlistJob( $title, [ 'username' => $user->getName(), 'pages' => $slice ] );
		}

		\JobQueueGroup::singleton()->push( $jobs );

		// groupmemberships contains groups and expiries, but is only present in recent versions of MW. Fall back to groups if it doesn't exist.
		$validGroups = array_diff( array_keys( $this->config->get( 'GroupPermissions' ) ), $this->config->get( 'ImplicitGroups' ) );
		$importableGroups = $this->config->get( 'MediaWikiAuthImportGroups' );
		if ( $importableGroups === false ) {
			// do not import any groups
			$validGroups = [];
		} elseif ( is_array( $importableGroups ) ) {
			// array_intersect has a mind-bogglingly stupid implementation,
			// in the sense that if the first array has dups, those dups are returned even if subsequent arrays don't have that element at all
			$validGroups = array_intersect( array_unique( $validGroups ), $importableGroups );
		}

		if ( isset( $userInfo->query->userinfo->groupmemberships ) ) {
			foreach ( $userInfo->query->userinfo->groupmemberships as $group ) {
				if ( !in_array( $group->group, $validGroups ) ) {
					continue;
				}

				$user->addGroup( $group->group, $group->expiry );
			}
		} else {
			foreach ( $userInfo->query->userinfo->groups as $group ) {
				if ( !in_array( $group, $validGroups ) ) {
					continue;
				}

				$user->addGroup( $group );
			}
		}

		if ( isset( $userInfo->query->userinfo->emailauthenticated ) ) {
			$user->setEmail( $userInfo->query->userinfo->email );
			$user->setEmailAuthenticationTimestamp( wfTimestamp( TS_MW, $userInfo->query->userinfo->emailauthenticated ) );
		} elseif ( isset( $userInfo->query->userinfo->email ) ) {
			$user->setEmailWithConfirmation( $userInfo->query->userinfo->email );
		}

		$validOptions = $user->getOptions();
		$validSkins = array_keys( \Skin::getAllowedSkins() );
		$optionBlacklist = [ 'watchlisttoken' ];

		foreach ( $userInfo->query->userinfo->options as $option => $value ) {
			if ( !in_array( $option, $optionBlacklist )
				&& ( $option !== 'skin' || in_array( $value, $validSkins ) )
				&& array_key_exists( $option, $validOptions )
				&& $validOptions[$option] !== $value
			) {
				$user->setOption( $option, $value );
			}
		}

		if ( isset( $userInfo->query->userinfo->messages ) ) {
			$user->setNewtalk( true );
		}

		// editcount and registrationdate cannot be set via methods on User
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update(
			'user',
			[
				'user_editcount' => $userInfo->query->userinfo->editcount,
				'user_registration' => $dbw->timestamp( $userInfo->query->userinfo->registrationdate )
			],
			[ 'user_id' => $user->getId() ],
			__METHOD__
		);
	}

	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		throw new \BadMethodCallException( 'This provider cannot be used for explicit account creation.' );
	}

	public function finishAccountCreation( $user, $creator, AuthenticationResponse $response ) {
		throw new \BadMethodCallException( 'This provider cannot be used for explicit account creation.' );
	}

	public function testUserExists( $username, $flags = User::READ_NORMAL ) {
		// sadly we have no other way of getting at the context here
		$user = \RequestContext::getMain()->getUser();
		if ( $user->isAllowed( 'mwa-createlocalaccount' ) ) {
			// bypass remote wiki checks; user can create local accounts
			return false;
		}

		if ( !isset( $this->userCache[$username] ) ) {
			$resp = $this->apiRequest( 'GET', [
				'action' => 'query',
				'list' => 'allusers',
				'aufrom' => $username,
				'auto' => $username,
				'aulimit' => 1,
			], [], __METHOD__ );

			// some MediaWikis *cough*Wikia*cough* display results for allusers even if there is no exact match
			// as such we test to ensure the username matches as well
			$this->userCache[$username] = count( $resp->query->allusers ) === 1 && $resp->query->allusers[0]->name === $username;
		}

		return $this->userCache[$username];
	}

	/**
	 * Performs an API request to the external wiki.
	 *
	 * @param string $method GET or POST
	 * @param array $params GET parameters to add to the API URL
	 * @param array $postData POST data
	 * @param string $caller Caller of this method for logging purposes
	 * @return object The parsed JSON result of the request
	 */
	protected function apiRequest( $method, array $params, array $postData = [], $caller = __METHOD__ ) {
		$baseUrl = $this->config->get( 'MediaWikiAuthApiUrl' );

		if ( !$baseUrl ) {
			throw new \ErrorPageError( 'mwa-unconfiguredtitle', 'mwa-unconfiguredtext' );
		}

		$params['format'] = 'json';
		$options = [ 'method' => $method ];
		$apiUrl = wfAppendQuery( $baseUrl, $params );

		if ( $method === 'POST' ) {
			$options['postData'] = $postData;
		}

		\MWDebug::log( "API Request: $method $apiUrl" );
		if ( $method === 'POST' ) {
			\MWDebug::log( 'POST data: ' . json_encode( $postData ) );
		}

		$req = \MWHttpRequest::factory( $apiUrl, $options, __METHOD__ );
		$req->setCookieJar( $this->cookieJar );
		$status = $req->execute();

		if ( $status->isOK() ) {
			$content = json_decode( $req->getContent() );

			if ( $content === null ) {
				// invalid JSON response, which means this isn't a valid API endpoint
				$logger = \LoggerFactory::getInstance( 'http' );
				$logger->error( 'Unable to parse JSON response from API endpoint: ' . json_last_error_msg(),
					[ 'endpoint' => $apiUrl, 'caller' => __METHOD__, 'content' => $req->getContent()] );
				throw new \ErrorPageError( 'mwa-unconfiguredtitle', 'mwa-unconfiguredtext' );
			}

			\MWDebug::log( 'API Response: ' . $req->getContent() );

			return $content;
		} else {
			$errors = $status->getErrorsByType( 'error' );
			$logger = \LoggerFactory::getInstance( 'http' );
			$logger->error( \Status::wrap( $status )->getWikiText( false, false, 'en' ),
				[ 'error' => $errors, 'caller' => __METHOD__, 'content' => $req->getContent() ] );

			// Might not be entirely accurate, as the error might be on the remote side...
			throw new \ErrorPageError( 'mwa-unconfiguredtitle', 'mwa-unconfiguredtext' );
		}
	}

	public function providerRevokeAccessForUser( $username ) {
		// no-op; ExternalWiki authentication has no notion of revoking access, as it does not
		// handle authentication once a local user account already exists.
	}

	public function providerAllowsPropertyChange( $property ) {
		return false;
	}

	public function providerAllowsAuthenticationDataChange(
		AuthenticationRequest $req, $checkData = true
	)
	{
		return Status::newGood( 'ignored' );
	}

	public function providerChangeAuthenticationData( AuthenticationRequest $req ) {
		// no-op
	}

	public function accountCreationType() {
		// while this creates accounts, it does not do so via the Special:CreateAccount UI
		return self::TYPE_NONE;
	}

	protected function getPasswordResetData( $username, $data ) {
		return (object)[
			'msg' => wfMessage( 'mwa-finishcreate', wfMessage( 'authprovider-resetpass-skip-label' )->text() ),
			'hard' => false
		];
	}
}
