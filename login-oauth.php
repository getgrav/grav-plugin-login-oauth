<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Session;
use Grav\Common\Twig\Twig;
use Grav\Common\Uri;
use Grav\Plugin\LoginOAuth\Controller as Controller;

/**
 * Class LoginOauthPlugin
 * @package Grav\Plugin
 */
class LoginOauthPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            // Registered unconditionally, admin included: a provider-only account
            // must not be reachable through any password form. Sits below Login's
            // special-case handlers (10000+) and above its password check (0).
            'onUserLoginAuthenticate' => ['userLoginAuthenticate', 100],
        ];
    }

    /**
     * Refuse local password authentication for provider-only accounts.
     *
     * These accounts hold a random placeholder password nobody was ever given, and
     * accounts created before this release hold one derived from the provider's
     * public user id. Either way the password is not a credential the account
     * holder chose, so the login form must not be able to authenticate with it.
     * Signing in through the provider does not pass through this event.
     */
    public function userLoginAuthenticate($event)
    {
        $user = $event->getUser();
        if (!$user->exists()) {
            return;
        }

        $oauth = (array) $user->get('oauth', []);
        if (empty($oauth['provider']) && !$this->isLegacyProviderAccount($user)) {
            return;
        }

        $event->setStatus($event::AUTHENTICATION_FAILURE);
        $event->stopPropagation();
    }

    /**
     * Recognize an account provisioned before provider markers existed.
     *
     * Those accounts only gain a marker the next time their holder signs in
     * through the provider, and until then their password is still the one
     * derived from the provider's public user id — so they have to be recognized
     * by the username this plugin generates, `<provider>.<id>`, using the
     * configured provider names rather than any username containing a dot.
     *
     * @param  object $user
     * @return bool
     */
    protected function isLegacyProviderAccount($user)
    {
        $username = (string) $user->get('username');
        $providers = (array) $this->grav['config']->get('plugins.login-oauth.providers', []);

        foreach (array_keys($providers) as $provider) {
            $prefix = strtolower($provider) . '.';
            if (strpos($username, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enable if not Admin
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            return;
        }

        // Check to ensure login plugin is enabled.
        if (!$this->grav['config']->get('plugins.login.enabled')) {
            throw new \RuntimeException('The Login plugin needs to be installed and enabled');
        }

        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $oauth = !empty($_POST['oauth']) ? $_POST['oauth'] : $uri->param('oauth');
        $oauth = $oauth ?: $this->grav['session']->oauth;
        $post = !empty($_POST) ? $_POST : [];

        /** @var Session */
        $session = $this->grav['session'];

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onLoginPage'         => ['onLoginPage', 0],
        ]);

        // Autoload classes
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new \Exception('Login-OAuth Plugin failed to load. Composer dependencies not met.');
        }
        require_once $autoload;

        // Manage OAuth login
        $task = !empty($post['task']) ? $post['task'] : $uri->param('task');
        if (!$task && isset($post['oauth']) || (!empty($_GET) && $session->oauth)) {
            require_once __DIR__ . '/classes/Controller.php';
            $controller = new Controller($this->grav, $oauth, $post);
            $controller->execute();
            $controller->redirect();
        }

        // Aborted OAuth authentication (invalidate it)
        unset($session->oauth);
    }

    /**
     * Add plugin templates path
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/login/templates';
    }

    /**
     * Add Twig Site Variables
     */
    public function onTwigSiteVariables()
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        $providers = [];
        foreach ($this->config->get('plugins.login-oauth.providers') as $provider => $options) {
            if ($options['enabled'] && isset($options['credentials'])) {
                $providers[$provider] = $options['credentials'];
            }
        }

        $twig->twig_vars['oauth'] = [
            'enabled'   => $this->config->get('plugins.login-oauth.enabled'),
            'providers' => $providers
        ];
        
        // add CSS for frontend if required
        if (!$this->isAdmin() && $this->config->get('plugins.login-oauth.built_in_css')) {
            $this->grav['assets']->add('plugin://login-oauth/css/login-oauth.css');
        }
    }

    /**
     * Add navigation item to the admin plugin
     */
    public function onLoginPage()
    {
        $this->grav['twig']->plugins_hooked_loginPage['LoginOauth'] = 'partials/login-oauth.html.twig';
    }
}
