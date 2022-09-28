<?php

namespace MediaWikiAuth;

use BadMethodCallException;
use CookieJar;
use ErrorPageError;
use JobQueueGroup;
use MediaWiki\Auth\AbstractPasswordPrimaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\User\TalkPageNotificationManager;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserOptionsManager;
use MWDebug;
use MWException;
use RequestContext;
use Skin;
use SkinFactory;
use Status;
use stdClass;
use Title;
use User;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Class ExternalWikiPrimaryAuthenticationProvider
 * @package MediaWikiAuth
 * @stable to extend (public/protected API versioned with semver)
 */
class ExternalWikiPrimaryAuthenticationProvider	extends AbstractPasswordPrimaryAuthenticationProvider {
	/** @var array Cache of users we've already looked up in the API */
	private $userCache = [];

	/** @var string Key containing the user's password stored as part of session manager state */
	private const PWKEY = 'MediaWikiAuth-userpw';

	/** @var UserGroupManager */
	protected $userGroupManager;

	/** @var UserOptionsManager */
	protected $userOptionsManager;

	/** @var TalkPageNotificationManager */
	protected $talkPageNotificationManager;

	/** @var SkinFactory */
	protected $skinFactory;

	/** @var HttpRequestFactory */
	protected $httpRequestFactory;

	/** @var ILoadBalancer */
	protected $loadBalancer;

	/** @var ?\MediaWiki\JobQueue\JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/** @var CookieJar */
	private $cookieJar;

	/**
	 * Constructor.
	 *
	 * @param ILoadBalancer $loadBalancer DBLoadBalancer
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param SkinFactory $skinFactory
	 * @param TalkPageNotificationManager $talkPageNotificationManager
	 * @param UserGroupManager $userGroupManager
	 * @param UserOptionsManager $userOptionsManager
	 * @param ?\MediaWiki\JobQueue\JobQueueGroupFactory $jobQueueGroupFactory
	 * @param array $params
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		HttpRequestFactory $httpRequestFactory,
		SkinFactory $skinFactory,
		TalkPageNotificationManager $talkPageNotificationManager,
		UserGroupManager $userGroupManager,
		UserOptionsManager $userOptionsManager,
		$jobQueueGroupFactory,
		array $params = []
	) {
		parent::__construct( $params );

		$this->userGroupManager = $userGroupManager;
		$this->userOptionsManager = $userOptionsManager;
		$this->talkPageNotificationManager = $talkPageNotificationManager;
		$this->skinFactory = $skinFactory;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->loadBalancer = $loadBalancer;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->cookieJar = new CookieJar();
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
	 *
	 * @param array $reqs
	 * @return AuthenticationResponse
	 * @throws ErrorPageError|MWException
	 */
	public function beginPrimaryAuthentication( array $reqs ) {
		// Enforce a password reset if accounts already exist locally with invalid hashes
		// Logging in with a Bot Password will also force a reset (handled later on in the function)
		$passwordResetData = new stdClass();
		$passwordResetData->hard = $this->config->get( 'MediaWikiAuthDisableAccountCreation' );

		/** @var PasswordAuthenticationRequest $req */
		$req = AuthenticationRequest::getRequestByClass( $reqs, PasswordAuthenticationRequest::class );
		if ( !$req ) {
			return AuthenticationResponse::newAbstain();
		}

		if ( $req->username === null || $req->password === null ) {
			return AuthenticationResponse::newAbstain();
		}

		// Test if we were given a bot name and password (aka if the username contains @)
		$botName = null;
		if ( strpos( $req->username, '@' ) !== false ) {
			[ $username, $botName ] = explode( '@', $req->username, 2 );
			$req->username = $username;
		}

		// Get an existing local user for this username. Depending on config,
		// we either block auth if a local user exists, or only allow auth against
		// local users (with currently-invalid passwords)
		$existingUser = User::newFromName( $req->username, 'usable' );

		// if $existingUser is false, the username is invalid
		if ( $existingUser === false ) {
			return AuthenticationResponse::newAbstain();
		}

		// Get canonical form of username
		$username = $existingUser->getName();

		if ( $this->config->get( 'MediaWikiAuthDisableAccountCreation' ) ) {
			// Only perform account import on already-existing local accounts,
			// instead of letting the extension automatically create users.
			// For security, we only do an import if the user currently has an invalid password
			// and is not a system user (system users have invalid tokens and no email).
			// We can't directly check for invalid tokens, but getToken() returns random results on each
			// call for system users, so that acts as a good proxy for an invalid token test.
			if ( $existingUser->getId() === 0
				|| $this->manager->userCanAuthenticate( $username )
				// @phan-suppress-next-line PhanPluginDuplicateExpressionBinaryOp
				|| ( !$existingUser->getEmail() && $existingUser->getToken() !== $existingUser->getToken() )
			) {
				return AuthenticationResponse::newAbstain();
			}
		} elseif ( $existingUser->getId() !== 0 ) {
			// user exists and we are set to only create new accounts; don't allow this auth attempt
			return AuthenticationResponse::newAbstain();
		}

		// Check for username existence on other wiki
		if ( !$this->testUserExistsRemote( $username ) ) {
			return AuthenticationResponse::newAbstain();
		}

		// Save the user password so we can set it in autoCreatedAccount (otherwise the user has
		// null credentials unless they go through the optional password change process)
		$this->manager->setAuthenticationSessionData( self::PWKEY, $req->password );

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
				$this->logger->info(
					'Authentication against legacy remote API failed for reason ' . $resp->login->result,
					[ 'remoteVersion' => $remoteVersion, 'caller' => __METHOD__, 'username' => $username ] );
				$this->manager->removeAuthenticationSessionData( self::PWKEY );
				return AuthenticationResponse::newFail( wfMessage( 'mwa-authfail' ) );
			}
		} elseif ( $botName !== null ) {
			// use login API with a BotPassword

			// Step 1. Grab a login token
			$resp = $this->apiRequest( 'GET', [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'login'
			], [], __METHOD__ );
			$loginToken = $resp->query->tokens->logintoken;

			// Step 2. use action=login with the bot name and password
			$resp = $this->apiRequest( 'POST', [
				'action' => 'login'
			], [
				'lgname' => $username . '@' . $botName,
				'lgpassword' => $req->password,
				'lgtoken' => $loginToken
			], __METHOD__ );

			// require a password reset if logging in with a bot password
			$passwordResetData->hard = true;

			if ( $resp->login->result !== 'Success' ) {
				$this->logger->info(
					'Authentication against BotPassword remote API failed for reason ' . $resp->login->result,
					[ 'remoteVersion' => $remoteVersion, 'caller' => __METHOD__, 'username' => $username ] );
				$this->manager->removeAuthenticationSessionData( self::PWKEY );
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
				'action' => 'clientlogin',
				'errorformat' => 'raw'
			], [
				'loginreturnurl' => $this->config->get( 'Server' ),
				'logintoken' => $loginToken,
				'username' => $username,
				'password' => $req->password
			], __METHOD__ );

			if ( isset( $resp->errors ) ) {
				$err = $resp->errors[0];
				$this->logger->info( 'Authentication against modern remote API failed for reason ' . $err->code,
					[ 'remoteVersion' => $remoteVersion, 'caller' => __METHOD__, 'username' => $username ] );
				$this->manager->removeAuthenticationSessionData( self::PWKEY );
				return AuthenticationResponse::newFail(
					wfMessage( 'mwa-authfail2', wfMessage( $err->key, $err->params )->plain() )
				);
			} elseif ( $resp->clientlogin->status !== 'PASS' ) {
				$this->logger->info(
					'Authentication against modern remote API failed for reason ' . $resp->clientlogin->status,
					[ 'remoteVersion' => $remoteVersion, 'caller' => __METHOD__, 'username' => $username ] );
				$this->manager->removeAuthenticationSessionData( self::PWKEY );
				return AuthenticationResponse::newFail( wfMessage( 'mwa-authfail' ) );
			}
		}

		// Remote login was successful, an account will be automatically created for the user by the system
		// Mark them as (maybe) needing to reset their password as a secondary auth step.
		if ( $this->config->get( 'MediaWikiAuthAllowPasswordChange' ) || $passwordResetData->hard ) {
			$this->setPasswordResetFlag( $username, Status::newGood(), $passwordResetData );
		}

		// If this was a stub user, import their data now
		if ( $existingUser->getId() !== 0 ) {
			if ( $this->importData( $existingUser ) !== null ) {
				// import successful, save updated options
				$existingUser->saveSettings();
			}
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

		// ensure the user can log in even if we don't do secondary password reset
		$password = $this->manager->getAuthenticationSessionData( self::PWKEY );
		$this->manager->removeAuthenticationSessionData( self::PWKEY );
		$user->changeAuthenticationData( [
			'username' => $user->getName(),
			'password' => $password,
			'retype' => $password
		] );

		// $user->saveChanges() is called automatically after this runs,
		// so calling it ourselves is not necessary.
		$userInfo = $this->importData( $user );

		if ( $this->config->get( 'MediaWikiAuthReattributeEdits' ) ) {
			$this->addReattributeEditsJobs( $user->getUserPage(), $user );
		}

		// editcount and registrationdate cannot be set via methods on User
		if ( $userInfo !== null ) {
			$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
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
	}

	/**
	 * Import data from the remote wiki for the given user, based on configuration:
	 * - watchlist
	 * - preferences
	 * - groups
	 *
	 * @param User $user
	 * @return ?stdClass API response, or null on error
	 */
	private function importData( User $user ) {
		$userInfo = $this->apiRequest( 'GET', [
			'action' => 'query',
			'meta' => 'userinfo',
			'uiprop' => 'blockinfo|hasmsg|editcount|groups|groupmemberships|options|email|realname|registrationdate',
			'assert' => 'user'
		], [], __METHOD__ );

		$wrquery = [
			'action' => 'query',
			'list' => 'watchlistraw',
			'wrprop' => 'changed',
			'wrlimit' => 'max',
			'assert' => 'user'
		];

		$watchlist = [];
		$pagesPerJob = (int)$this->config->get( 'UpdateRowsPerJob' );

		// enqueue jobs to actually add watchlist items and to reattribute already-existing edits (if enabled)
		if ( $this->config->get( 'MediaWikiAuthImportWatchlist' ) ) {
			$jobs = [];

			while ( true ) {
				$resp = $this->apiRequest( 'GET', $wrquery, [], __METHOD__ );
				if ( isset( $resp->error ) ) {
					$this->logger->error(
						'Unable to load remote user watchlist due to error in remote API: ' . $resp->error->info,
						$resp->error
					);

					break;
				}

				$watchlist = array_merge( $watchlist, $resp->watchlistraw );

				if ( !isset( $resp->{'query-continue'} ) ) {
					break;
				}

				$wrquery['wrcontinue'] = $resp->{'query-continue'}->watchlistraw->wrcontinue;
			}

			while ( $watchlist ) {
				// array_splice reduces the size of $watchlist and returns the removed elements.
				// This avoids memory bloat so that we only keep the watchlist resident in memory one time.
				$slice = array_splice( $watchlist, 0, $pagesPerJob );
				$jobs[] = new PopulateImportedWatchlistJob( $user->getUserPage(), [
					'username' => $user->getName(),
					'pages' => $slice
				] );
			}

			if ( $jobs !== [] ) {
				$this->pushJobs( $jobs );
			}
		}

		if ( isset( $userInfo->error ) ) {
			$this->logger->error(
				'Unable to load remote user information due to error in remote API: ' . $userInfo->error->info,
				$userInfo->error
			);

			return null;
		}

		// groupmemberships contains groups and expiries, but is only present in recent versions of MW.
		// Fall back to groups if it doesn't exist.
		$validGroups = array_diff(
			array_keys( $this->config->get( 'GroupPermissions' ) ),
			$this->config->get( 'ImplicitGroups' )
		);

		$importableGroups = $this->config->get( 'MediaWikiAuthImportGroups' );
		if ( $importableGroups === false ) {
			// do not import any groups
			$validGroups = [];
		} elseif ( is_array( $importableGroups ) ) {
			// array_intersect has a mind-bogglingly stupid implementation,
			// in the sense that if the first array has dups, those dups are returned even if subsequent arrays
			// don't have that element at all
			$validGroups = array_intersect( array_unique( $validGroups ), $importableGroups );
		}

		if ( isset( $userInfo->query->userinfo->groupmemberships ) ) {
			foreach ( $userInfo->query->userinfo->groupmemberships as $group ) {
				if ( !in_array( $group->group, $validGroups ) ) {
					continue;
				}

				if ( in_array( $group->expiry, [ 'infinite', 'indefinite', 'infinity', 'never' ] ) ) {
					$group->expiry = null;
				}

				$this->userGroupManager->addUserToGroup( $user, $group->group, $group->expiry );
			}
		} else {
			foreach ( $userInfo->query->userinfo->groups as $group ) {
				if ( !in_array( $group, $validGroups ) ) {
					continue;
				}

				$this->userGroupManager->addUserToGroup( $user, $group );
			}
		}

		if ( isset( $userInfo->query->userinfo->emailauthenticated ) ) {
			$user->setEmail( $userInfo->query->userinfo->email );
			$user->setEmailAuthenticationTimestamp(
				wfTimestamp( TS_MW, $userInfo->query->userinfo->emailauthenticated )
			);
		} elseif ( isset( $userInfo->query->userinfo->email ) ) {
			$user->setEmailWithConfirmation( $userInfo->query->userinfo->email );
		}

		$validOptions = $this->userOptionsManager->getOptions( $user );
		if ( method_exists( $this->skinFactory, 'getAllowedSkins' ) ) {
			// 1.36+
			// @phan-suppress-next-line PhanUndeclaredMethod
			$validSkins = array_keys( $this->skinFactory->getAllowedSkins() );
		} else {
			// @phan-suppress-next-line PhanUndeclaredStaticMethod
			$validSkins = array_keys( Skin::getAllowedSkins() );
		}

		$optionImportList = $this->config->get( 'MediaWikiAuthImportOptions' );
		$optionSkipList = $this->config->get( 'MediaWikiAuthSkipOptions' );
		if ( in_array( '*', $optionImportList ) ) {
			$optionImportList = array_merge( $optionImportList, $validOptions );
		}

		$optionSkipList[] = 'watchlisttoken';
		$optionList = array_diff( $optionImportList, $optionSkipList );

		foreach ( $userInfo->query->userinfo->options as $option => $value ) {
			if ( in_array( $option, $optionList )
				&& ( $option !== 'skin' || in_array( $value, $validSkins ) )
				&& array_key_exists( $option, $validOptions )
				&& $validOptions[$option] !== $value
			) {
				$this->userOptionsManager->setOption( $user, $option, $value );
			}
		}

		return $userInfo;
	}

	/**
	 * @param Title $title
	 * @param User $user
	 */
	private function addReattributeEditsJobs( Title $title, User $user ) {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$pagesPerJob = (int)$this->config->get( 'UpdateRowsPerJob' );
		$jobs = [];

		foreach ( ReattributeEdits::getTableMetadata() as $table => $metadata ) {
			[ $tableKey, $actorKey, $userText, $userKey ] = $metadata;

			// determine which records need to be updated
			$data = ReattributeEdits::getActorMigrationData( $dbr, $user->getName() );
			if ( $data === [] ) {
				// nothing to reattribute
				return;
			}

			$res = $dbr->select(
				$table,
				$tableKey,
				[ $actorKey => array_keys( $data ) ],
				__METHOD__ . ':populateJobs'
			);

			// generate our jobs, in appropriate batch sizes
			$counter = 0;
			$ids = [];
			foreach ( $res as $row ) {
				$counter++;
				$ids[] = $row->$tableKey;

				if ( $counter === $pagesPerJob ) {
					$jobs[] = new ReattributeImportedEditsJob( $title, [
						'username' => $user->getName(),
						'table' => $table,
						'ids' => $ids
					] );
					$counter = 0;
					$ids = [];
				}
			}

			if ( $counter > 0 ) {
				$jobs[] = new ReattributeImportedEditsJob( $title, [
					'username' => $user->getName(),
					'table' => $table,
					'ids' => $ids
				] );
			}
		}

		// handle log_search table specially since it doesn't conform to the other tables we update
		$data = ReattributeEdits::getActorMigrationData( $dbr, $user->getName() );
		$res = $dbr->select(
			'log_search',
			'ls_log_id',
			[ 'ls_field' => 'target_author_actor', 'ls_value' => array_keys( $data ) ],
			__METHOD__ . ':populateJobs',
			[ 'DISTINCT' ]
		);

		$counter = 0;
		$ids = [];
		foreach ( $res as $row ) {
			$counter++;
			$ids[] = $row->ls_log_id;

			if ( $counter === $pagesPerJob ) {
				$jobs[] = new ReattributeImportedEditsJob( $title, [
					'username' => $user->getName(),
					'table' => 'log_search',
					'ids' => $ids
				] );
				$counter = 0;
				$ids = [];
			}
		}

		if ( $counter > 0 ) {
			$jobs[] = new ReattributeImportedEditsJob( $title, [
				'username' => $user->getName(),
				'table' => 'log_search',
				'ids' => $ids
			] );
		}

		if ( $jobs !== [] ) {
			$this->pushJobs( $jobs );
		}
	}

	/**
	 * Back-compat wrapper to add jobs to the job queue
	 *
	 * @param array $jobs
	 * @return void
	 */
	protected function pushJobs( array $jobs ) {
		if ( $this->jobQueueGroupFactory !== null ) {
			// 1.37+
			// @phan-suppress-next-line PhanUndeclaredClassMethod
			$this->jobQueueGroupFactory->makeJobQueueGroup()->push( $jobs );
		} else {
			// @phan-suppress-next-line PhanUndeclaredStaticMethod
			JobQueueGroup::singleton()->push( $jobs );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		throw new BadMethodCallException( 'This provider cannot be used for explicit account creation.' );
	}

	/**
	 * @inheritDoc
	 */
	public function finishAccountCreation( $user, $creator, AuthenticationResponse $response ) {
		throw new BadMethodCallException( 'This provider cannot be used for explicit account creation.' );
	}

	/**
	 * Determine if the username exists on the remote wiki
	 *
	 * @param string $username
	 * @return bool
	 * @throws ErrorPageError|MWException
	 */
	public function testUserExistsRemote( $username ) {
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
			$this->userCache[$username] = count( $resp->query->allusers ) === 1
				&& $resp->query->allusers[0]->name === $username;
		}

		return $this->userCache[$username];
	}

	/**
	 * @inheritDoc
	 * @throws ErrorPageError|MWException
	 */
	public function testUserExists( $username, $flags = User::READ_NORMAL ) {
		// sadly we have no other way of getting at the context here
		$user = RequestContext::getMain()->getUser();
		// bypass remote wiki checks; user can create local accounts
		if (
			$this->config->get( 'MediaWikiAuthDisableAccountCreation' )
			|| $user->isAllowed( 'mwa-createlocalaccount' )
		) {
			return false;
		}

		return $this->testUserExistsRemote( $username );
	}

	/**
	 * Performs an API request to the external wiki.
	 *
	 * @param string $method GET or POST
	 * @param array $params GET parameters to add to the API URL
	 * @param array $postData POST data
	 * @param string $caller Caller of this method for logging purposes
	 * @return stdClass The parsed JSON result of the request
	 * @throws ErrorPageError|MWException
	 */
	protected function apiRequest( $method, array $params, array $postData = [], $caller = __METHOD__ ) {
		$baseUrl = $this->config->get( 'MediaWikiAuthApiUrl' );

		if ( !$baseUrl ) {
			throw new ErrorPageError( 'mwa-unconfiguredtitle', 'mwa-unconfiguredtext' );
		}

		$params['format'] = 'json';
		$options = [ 'method' => $method ];
		$apiUrl = wfAppendQuery( $baseUrl, $params );

		if ( $method === 'POST' ) {
			$options['postData'] = $postData;
		}

		MWDebug::log( "API Request: $method $apiUrl" );
		if ( $method === 'POST' ) {
			MWDebug::log( 'POST data: ' . json_encode( $postData ) );
		}

		$req = $this->httpRequestFactory->create( $apiUrl, $options, $caller );
		$req->setCookieJar( $this->cookieJar );
		$status = $req->execute();

		if ( $status->isOK() ) {
			$content = json_decode( $req->getContent() );

			if ( $content === null ) {
				// invalid JSON response, which means this isn't a valid API endpoint
				$logger = LoggerFactory::getInstance( 'http' );
				$logger->error( 'Unable to parse JSON response from API endpoint: ' . json_last_error_msg(),
					[ 'endpoint' => $apiUrl, 'caller' => $caller, 'content' => $req->getContent() ] );
				throw new ErrorPageError( 'mwa-unconfiguredtitle', 'mwa-unconfiguredtext' );
			}

			MWDebug::log( 'API Response: ' . $req->getContent() );

			return $content;
		} else {
			$errors = $status->getErrorsByType( 'error' );
			$logger = LoggerFactory::getInstance( 'http' );
			$logger->error( Status::wrap( $status )->getWikiText( false, false, 'en' ),
				[ 'error' => $errors, 'caller' => $caller, 'content' => $req->getContent() ] );

			// Might not be entirely accurate, as the error might be on the remote side...
			throw new ErrorPageError( 'mwa-unconfiguredtitle', 'mwa-unconfiguredtext' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function providerRevokeAccessForUser( $username ) {
		// no-op; ExternalWiki authentication has no notion of revoking access, as it does not
		// handle authentication once a local user account already exists.
	}

	/**
	 * @inheritDoc
	 */
	public function providerAllowsPropertyChange( $property ) {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function providerAllowsAuthenticationDataChange( AuthenticationRequest $req, $checkData = true ) {
		return Status::newGood( 'ignored' );
	}

	/**
	 * @inheritDoc
	 */
	public function providerChangeAuthenticationData( AuthenticationRequest $req ) {
		// no-op
	}

	/**
	 * @inheritDoc
	 */
	public function accountCreationType() {
		// while this creates accounts, it does not do so via the Special:CreateAccount UI
		return self::TYPE_NONE;
	}

	/**
	 * @inheritDoc
	 */
	protected function getPasswordResetData( $username, $data ) {
		if ( isset( $data->hard ) && $data->hard ) {
			// In this case, an account exists locally with an invalid password.
			// The user must reset their password to something valid or
			// they will be unable to log in, and it'll try to fire off another import.
			return (object)[
				'msg' => wfMessage( 'mwa-finishimport-nocreate' ),
				'hard' => true
			];
		} else {
			// Don't require a password reset if we created an account on a user's behalf,
			// as our account creation code gives them the same password they used to log into the remote wiki.
			// We offer them an opportunity to change it, however, as password re-use is bad.
			return (object)[
				'msg' => wfMessage( 'mwa-finishcreate', wfMessage( 'authprovider-resetpass-skip-label' )->text() ),
				'hard' => false
			];
		}
	}
}
