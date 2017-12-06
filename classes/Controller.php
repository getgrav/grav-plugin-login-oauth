<?php
namespace Grav\Plugin\LoginOAuth;

use Grav\Common\Grav;
use Grav\Common\User\User;
use OAuth\ServiceFactory;
use OAuth\Common\Storage\Session;
use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Client\CurlClient;

/**
 * OAuthLoginController
 *
 * Handles OAuth authentication.
 *
 * @author  RocketTheme
 * @author  Sommerregen <sommerregen@benjamin-regler.de>
 */
class Controller extends \Grav\Plugin\Login\Controller
{
    /**
     * @var string
     */
    public $provider;
    /**
     * @var \OAuth\Common\Storage\Session
     */
    protected $storage;
    /**
     * @var \OAuth\ServiceFactory
     */
    protected $factory;

    /**
     * @var \OAuth\Common\Service\AbstractService
     */
    protected $service;

    /**
     * @var string
     */
    protected $prefix = 'oauth';

    /**
     * @var array
     */
    protected $scopes = [
        'github'   => ['user'],
        'google'   => ['userinfo_email', 'userinfo_profile'],
        'facebook' => ['public_profile'],
        'linkedin' => ['r_basicprofile', 'r_emailaddress']
    ];

    /**
     * Constructor.
     *
     * @param Grav   $grav   Grav instance
     * @param string $action The name of the action
     * @param array  $post   An array of values passed to the action
     */
    public function __construct(Grav $grav, $action, $post = null)
    {
        parent::__construct($grav, ucfirst($action), $post);

        // Session storage
        $this->storage = new Session(false, 'oauth_token', 'oauth_state');
        /** @var $serviceFactory \OAuth\ServiceFactory */
        $this->factory = new ServiceFactory();
        // Use curl client instead of fopen stream
        if (extension_loaded('curl')) {
            $this->factory->setHttpClient(new CurlClient());
        }
        // Check configuration
        if( $this->grav['config']->get('plugins.login-oauth.providers.Facebook.enable_email') ){
          array_push( $this->scopes['facebook'] , 'email' );
        }
    }

    /**
     * Performs an OAuth authentication
     */
    public function execute()
    {
        /** @var \Grav\Common\Language\Language */
        $t = $this->grav['language'];
        $messages = $this->grav['messages'];
        $provider = strtolower($this->action);
        $config = $this->grav['config']->get('plugins.login-oauth.providers.' . $this->action, []);

        if (isset($config['credentials'])) {
            // Setup the credentials for the requests
            $credentials = new Credentials($config['credentials']['key'], $config['credentials']['secret'], $this->grav['uri']->url(true));
            // Instantiate service using the credentials, http client
            // and storage mechanism for the token
            $scope = isset($this->scopes[$provider]) ? $this->scopes[$provider] : [];
            $this->service = $this->factory->createService($this->action, $credentials, $this->storage, $scope);
        }
        if (!$this->service || empty($config)) {
            $messages->add($t->translate(['PLUGIN_LOGIN_OAUTH.OAUTH_PROVIDER_NOT_SUPPORTED', $this->action]));

            return true;
        }

        // Check OAuth authentication status
        $authenticated = parent::execute();

        if (is_bool($authenticated)) {
            $this->reset();
            if ($authenticated) {
                $messages->add($t->translate('PLUGIN_LOGIN.LOGIN_SUCCESSFUL'));
            } else {
                $messages->add($t->translate('PLUGIN_LOGIN.ACCESS_DENIED'));
            }

            // Redirect to current URI
            $redirect = $this->grav['config']->get('plugins.login.redirect_after_login');
            if (!$redirect) {
                $redirect = $this->grav['session']->redirect_after_login;
            }
            $this->setRedirect($redirect);
        } elseif (!$this->grav['session']->oauth) {
            $messages->add($t->translate(['PLUGIN_LOGIN_OAUTH.OAUTH_PROVIDER_NOT_SUPPORTED', $this->action]));
        }

        return true;
    }

    /**
     * Reset state of OAuth authentication.
     */
    public function reset()
    {
        /** @var Session */
        $session = $this->grav['session'];
        unset($session->oauth);
        $this->storage->clearAllTokens();
        $this->storage->clearAllAuthorizationStates();
    }

    /**
     * Implements a generic OAuth service provider authentication
     *
     * @param  callable $callback A callable to call when OAuth authentication
     *                            starts
     * @param  string   $oauth    OAuth version to be used for authentication
     *
     * @return null|User          Returns a Grav user instance on success.
     */
    protected function genericOAuthProvider($callback, $oauth = 'oauth2')
    {
        /** @var Session */
        $session = $this->grav['session'];

        switch ($oauth) {
            case 'oauth1':
                if (empty($_GET['oauth_token']) && empty($_GET['oauth_verifier'])) {
                    // Extra request needed for OAuth1 to request a request token :-)
                    $token = $this->service->requestRequestToken();
                    // Create a state token to prevent request forgery.
                    // Store it in the session for later validation.
                    $redirect = $this->service->getAuthorizationUri([
                        'oauth_token' => $token->getRequestToken()
                    ]);
                    $this->setRedirect($redirect);
                    // Update OAuth session
                    $session->oauth = $this->action;
                } else {
                    $token = $this->storage->retrieveAccessToken($session->oauth);
                    // This was a callback request from OAuth1 service, get the token
                    if (isset($_GET['_url'])) {
                      parse_str(parse_url($_GET['_url'])['query']);
                      $this->service->requestAccessToken($oauth_token, $_GET['oauth_verifier'],
                          $token->getRequestTokenSecret());
                    } else {
                      $this->service->requestAccessToken($_GET['oauth_token'], $_GET['oauth_verifier'],
                          $token->getRequestTokenSecret());
                    }

                    return $callback();
                }
                break;
            case 'oauth2':
            default:
                if (empty($_GET['code'])) {
                    // Create a state token to prevent request forgery (CSRF).
                    $state = sha1($this->getRandomBytes(1024, false));
                    $redirect = $this->service->getAuthorizationUri([
                        'state' => $state
                    ]);
                    $this->setRedirect($redirect);
                    // Update OAuth session
                    $session->oauth = $this->action;
                    // Store CSRF in the session for later validation.
                    $this->storage->storeAuthorizationState($this->action, $state);
                } else {
                    // Retrieve the CSRF state parameter
                    $state = isset($_GET['state']) ? $_GET['state'] : null;
                    // This was a callback request from the OAuth2 service, get the token
                    $this->service->requestAccessToken($_GET['code'], $state);

                    return $callback();
                }
                break;
        }

        return;
    }

    /**
     * Implements OAuth authentication for Facebook
     *
     * @return null|bool          Returns a boolean on finished authentication.
     */
    public function oauthFacebook()
    {
        return $this->genericOAuthProvider(function () {
            // Send a request now that we have access token
            $fields_query='';
            if( $this->grav['config']->get('plugins.login-oauth.providers.Facebook.enable_email') ){
              $fields_query = '?fields=id,name,email';
            }
            $data = json_decode($this->service->request('/me'.$fields_query), true);
            $email = isset($data['email']) ? $data['email'] : '';

            $dataUser = [
                'id'       => $data['id'],
                'fullname' => $data['name'],
                'email'    => $email
            ];
            // Authenticate OAuth user against Grav system.
            return $this->authenticateOAuth($dataUser);
        });
    }

    /**
     * Implements OAuth authentication for Google
     *
     * @return null|bool          Returns a boolean on finished authentication.
     */
    public function oauthGoogle()
    {
        return $this->genericOAuthProvider(function () {
            /** @var \Grav\Common\Language\Language */
            $t = $this->grav['language'];
            $messages = $this->grav['messages'];

            // Get fullname, email and language
            $data = json_decode($this->service->request('userinfo'), true);

            if ( $this->grav['config']->get('plugins.login-oauth.providers.Google.whitelist') ) {
                $whitelist = $this->grav['config']->get('plugins.login-oauth.providers.Google.whitelist', []);

                $domain = isset($data['hd'])?$data['hd']:'gmail.com';

                if ( !in_array($domain, $whitelist) ) {
                    $messages->add($t->translate(['PLUGIN_LOGIN_OAUTH.EMAIL_DOMAIN_NOT_PERMITTED', $domain]));
                    return null;
                }
            }

            if ( $this->grav['config']->get('plugins.login-oauth.providers.Google.blacklist') ) {
                $blacklist = $this->grav['config']->get('plugins.login-oauth.providers.Google.blacklist', []);
                $domain = isset($data['hd'])?$data['hd']:'gmail.com';

                if( in_array($domain, $blacklist)) {
                    $messages->add($t->translate(['PLUGIN_LOGIN_OAUTH.EMAIL_DOMAIN_NOT_PERMITTED', $domain]));
                    return null;
                }
            }
            $fullname = $data['given_name'] . ' ' . $data['family_name'];
            if (preg_match('~[\w\s]+\((\w+)\)~i', $data['name'], $matches)) {
                $fullname = $matches[1];
            }
            $lang = isset($data['lang']) ? $data['lang'] : '';

            $dataUser = [
                'id'       => $data['id'],
                'fullname' => $fullname,
                'email'    => $data['email']
            ];
            // Authenticate OAuth user against Grav system.
            return $this->authenticateOAuth($dataUser, $lang);
        });
    }

    /**
     * Implements OAuth authentication for GitHub
     *
     * @return null|bool          Returns a boolean on finished authentication.
     */
    public function oauthGitHub()
    {
        return $this->genericOAuthProvider(function () {
            // Get username, email and language
            $user = json_decode($this->service->request('user'), true);
            $emails = json_decode($this->service->request('user/emails'), true);
            $fullname = !empty($user['name'])?$user['name']:$user['login'];

            $dataUser = [
                'id'       => $user['id'],
                'fullname' => $fullname,
                'email'    => reset($emails)
            ];
            // Authenticate OAuth user against Grav system.
            return $this->authenticateOAuth($dataUser);
        });
    }

    /**
     * Implements OAuth authentication for Twitter
     *
     * @return null|bool          Returns a boolean on finished authentication.
     */
    public function oauthTwitter()
    {
        return $this->genericOAuthProvider(function () {
            // Get username, email and language
            $data = json_decode($this->service->request('account/verify_credentials.json?include_email=true'), true);
            $lang = isset($data['lang']) ? $data['lang'] : '';

            $dataUser = [
                'id'       => $data['id'],
                'fullname' => $data['screen_name'],
                'email'    => ''
            ];

            // Authenticate OAuth user against Grav system.
            return $this->authenticateOAuth($dataUser, $lang);
        }, 'oauth1');
    }

    /**
     * Implements OAuth authentication for Linkedin
     *
     * @return null|bool          Returns a boolean on finished authentication.
     */
    public function oauthLinkedin()
    {
        return $this->genericOAuthProvider(function () {
            // Get id, full name, email and language
            $profile = simplexml_load_string($this->service->request('people/~:(id,first-name,last-name,email-address,location)'));
            $id = (string)$profile->{"id"};
            $fullname = (string)$profile->{"first-name"}.' '.$profile->{"last-name"};
            $email_address = (string)$profile->{"email-address"};
            $lang = isset($profile->location->country->code) ? (string)$profile->location->country->code : '';

            $dataUser = [
                'id'       => $id,
                'fullname' => $fullname,
                'email'    => $email_address
            ];

            // Authenticate OAuth user against Grav system.
            return $this->authenticateOAuth($dataUser, $lang);
        });
    }

    /**
     * Get the user identifier
     *
     * @param string $id The user ID on the service
     *
     * @return string
     */
    private function getUsername($id)
    {
        $service_identifier = $this->action;
        $user_identifier = $this->grav['inflector']->underscorize($id);
        return strtolower("$service_identifier.$user_identifier");
    }

    /**
     * Authenticate user.
     *
     * @param  string $data             ['fullname'] The user name of the OAuth user
     * @param  string $data             ['id']       The id of the OAuth user
     * @param  string $data             ['email']    The email of the OAuth user
     * @param  string $language                      Language
     *
     * @return bool True if user was authenticated
     */
    protected function authenticateOAuth($data, $language = '')
    {
        $username = $this->getUsername($data['id']);
        $user = User::load($username);
        $password = md5($data['id']);

        if (!$user->exists()) {
            // Create the user
            $user = $this->createUser([
                'id'       => $data['id'],
                'fullname' => $data['fullname'],
                'username' => $username,
                'email'    => $data['email'],
                'lang'     => $language,
            ]);

            $authenticated = true;
            $user->authenticated = true;
            $user->save();

        } else {
            $authenticated = $user->authenticate($password);
            // Save new email if different.
            if( $authenticated && $data['email'] != $user->get('email') ){
                $user->set('email', $data['email'] );
                $user->save();
            }
        }

        // Store user in session
        if ($authenticated) {
            $this->grav['session']->user = $user;
            unset($this->grav['user']);
            $this->grav['user'] = $user;
        }

        return $authenticated;
    }

    /**
     * Create user.
     *
     * @param  string $data               ['username']   The username of the OAuth user
     * @param  string $data               ['password']   The unique id of the OAuth user
     *                                    setting as password
     * @param  string $data               ['email']      The email of the OAuth user
     * @param  string $data               ['language']   Language
     *
     * @return User                       A user object
     */
    protected function createUser($data)
    {
        $id = $data['id'];

        $data['password'] = md5($id);
        $data['state'] = 'enabled';

        return $this->login->register($data);
    }

    /**
     * Generates Random Bytes for the given $length.
     *
     * @param  int  $length The number of bytes to generate
     * @param  bool $secure Return cryptographic secure string or not
     *
     * @return string
     *
     * @throws InvalidArgumentException when an invalid length is specified.
     * @throws RuntimeException when no secure way of making bytes is posible
     */
    protected function getRandomBytes($length = 0, $secure = true)
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('The length parameter must be a number greater than zero!');
        }
        /**
         * Our primary choice for a cryptographic strong randomness function is
         * openssl_random_pseudo_bytes.
         */
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length, $sec);
            if ($sec === true) {
                return $bytes;
            }
        }
        /**
         * If mcrypt extension is available then we use it to gather entropy from
         * the operating system's PRNG. This is better than reading /dev/urandom
         * directly since it avoids reading larger blocks of data than needed.
         * Older versions of mcrypt_create_iv may be broken or take too much time
         * to finish so we only use this function with PHP 5.3.7 and above.
         * @see https://bugs.php.net/bug.php?id=55169
         */
        if (function_exists('mcrypt_create_iv') && (strtolower(substr(PHP_OS, 0,
                    3)) !== 'win' || version_compare(PHP_VERSION, '5.3.7') >= 0)
        ) {
            $bytes = mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);
            if ($bytes !== false) {
                return $bytes;
            }
        }
        if ($secure) {
            throw new \RuntimeException('There is no possible way of making secure bytes');
        }

        /**
         * Fallback (not really secure, but better than nothing)
         */
        return hex2bin(substr(str_shuffle(str_repeat('0123456789abcdef', $length * 16)), 0, $length));
    }
}
