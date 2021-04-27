<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Config\Config;
use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Common\Scheduler\Scheduler;
use Grav\Plugin\GitSync\AdminController;
use Grav\Plugin\GitSync\GitSync;
use Grav\Plugin\GitSync\Helper;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class GitSyncPlugin
 *
 * @package Grav\Plugin
 */
class GitSyncPlugin extends Plugin
{
    /** @var AdminController|null */
    protected $controller;
    /** @var GitSync */
    protected $git;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized'   => [
                ['autoload', 100000],
                ['onPluginsInitialized', 1000]
            ],
            'onPageInitialized'      => ['onPageInitialized', 0],
            'onFormProcessed'        => ['onFormProcessed', 0],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0]
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload() : ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * @return string
     */
    public static function generateWebhookSecret()
    {
        return static::generateHash(24);
    }

    /**
     * @return string
     */
    public static function generateRandomWebhook()
    {
        return '/_git-sync-' . static::generateHash(6);
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        $this->enable(['gitsync' => ['synchronize', 0]]);
        $this->init();

        if ($this->isAdmin()) {
            $this->enable([
                'onTwigTemplatePaths'  => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables'  => ['onTwigSiteVariables', 0],
                'onAdminMenu'          => ['onAdminMenu', 0],
                'onAdminSave'          => ['onAdminSave', 0],
                'onAdminAfterSave'     => ['onAdminAfterSave', 0],
                'onAdminAfterSaveAs'   => ['onAdminAfterSaveAs', 0],
                'onAdminAfterDelete'   => ['onAdminAfterDelete', 0],
                'onAdminAfterAddMedia' => ['onAdminAfterMedia', 0],
                'onAdminAfterDelMedia' => ['onAdminAfterMedia', 0],
            ]);

            return;
        }

        $config = $this->config->get('plugins.' . $this->name);
        $route = $this->grav['uri']->route();
        $webhook = $config['webhook'] ?? false;
        $secret = $config['webhook_secret'] ?? false;
        $enabled = $config['webhook_enabled'] ?? false;

        if ($route === $webhook && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($secret && $enabled) {
                if (!$this->isRequestAuthorized($secret)) {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Unauthorized request'
                    ]);
                    exit;
                }
            }
            try {
                $this->synchronize();
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success',
                    'message' => 'GitSync completed the synchronization'
                ]);
            } catch (\Exception $e) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'GitSync failed to synchronize'
                ]);
            }
            exit;
        }
    }

    /**
     * Returns true if the request contains a valid signature or token
     * @param  string $secret local secret
     * @return bool           whether or not the request is authorized
     */
    public function isRequestAuthorized($secret)
    {
        if (isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
            $payload = file_get_contents('php://input') ?: '';

            return $this->isGithubSignatureValid($secret, $_SERVER['HTTP_X_HUB_SIGNATURE'], $payload);
        }
        if (isset($_SERVER['HTTP_X_GITLAB_TOKEN'])) {
            return $this->isGitlabTokenValid($secret, $_SERVER['HTTP_X_GITLAB_TOKEN']);
        } else {
            $payload = file_get_contents('php://input');
            return $this->isGiteaSecretValid($secret, $payload);
        }

        return false;
    }

    /**
     * Hashes the webhook request body with the client secret and
     * checks if it matches the webhook signature header
     * @param  string $secret The webhook secret
     * @param  string $signatureHeader The signature of the webhook request
     * @param  string $payload The webhook request body
     * @return bool            Whether the signature is valid or not
     */
    public function isGithubSignatureValid($secret, $signatureHeader, $payload)
    {
        [$algorithm, $signature] = explode('=', $signatureHeader);

        return $signature === hash_hmac($algorithm, $payload, $secret);
    }

    /**
     * Returns true if given Gitlab token matches secret
     * @param  string $secret local secret
     * @param  string $token token received from Gitlab webhook request
     * @return bool          whether or not secret and token match
     */
    public function isGitlabTokenValid($secret, $token)
    {
        return $secret === $token;
    }

    /**
     * Returns true if secret contained in the payload matches the client
     * secret
     * @param  string $secret The webhook secret
     * @param  string $payload The webhook request body
     * @return boolean Whether the client secret matches the payload secret or
     * not
     */
    public function isGiteaSecretValid($secret, $payload)
    {
        $payload = json_decode($payload, true);
        if (!empty($payload) && isset($payload['secret'])) {
            return $secret === $payload['secret'];
        }

        return false;
    }

    public function onAdminMenu()
    {
        $base = rtrim($this->grav['base_url'], '/') . '/' . trim($this->grav['admin']->base, '/');
        $options = [
            'hint' => Helper::isGitInitialized() ? 'Synchronize GitSync' : 'Configure GitSync',
            'class' => 'gitsync-sync',
            'location' => 'pages',
            'route' => Helper::isGitInitialized() ? 'admin' : 'admin/plugins/git-sync',
            'icon' => 'fa-' . $this->grav['plugins']->get('git-sync')->blueprints()->get('icon')
        ];

        if (Helper::isGitInstalled()) {
            if (Helper::isGitInitialized()) {
                $options['data'] = [
                    'gitsync-useraction' => 'sync',
                    'gitsync-uri' => $base . '/plugins/git-sync'
                ];
            }

            $this->grav['twig']->plugins_quick_tray['GitSync'] = $options;
        }
    }

    public function init()
    {
        if ($this->isAdmin()) {
            /** @var AdminController controller */
            $this->controller = new AdminController($this);
            $this->git = &$this->controller->git;
        } else {
            $this->git = new GitSync();
        }
    }

    /**
     * @return bool
     */
    public function synchronize()
    {
        if (!Helper::isGitInstalled() || !Helper::isGitInitialized()) {
            return true;
        }

        $this->grav->fireEvent('onGitSyncBeforeSynchronize');

        if ($this->git->hasChangesToCommit()) {
            $this->git->commit();
        }

        // synchronize with remote
        $this->git->sync();

        $this->grav->fireEvent('onGitSyncAfterSynchronize');

        return true;
    }

    public function onSchedulerInitialized(Event $event)
    {
        /** @var Config $config */
        $config = Grav::instance()['config'];
        $run_at = $config->get('plugins.git-sync.sync.cron_at', '0 12,23 * * *');

        if ($config->get('plugins.git-sync.sync.cron_enable', false)) {
            /** @var Scheduler $scheduler */
            $scheduler = $event['scheduler'];
            $job = $scheduler->addFunction('Grav\Plugin\GitSync\Helper::synchronize', [], 'GitSync');
            $job->at($run_at);
        }
    }

    /**
     * @return bool
     */
    public function reset()
    {
        if (!Helper::isGitInstalled() || !Helper::isGitInitialized()) {
            return true;
        }

        $this->grav->fireEvent('onGitSyncBeforeReset');

        $this->git->reset();

        $this->grav->fireEvent('onGitSyncAfterReset');

        return true;
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Set needed variables to display cart.
     *
     * @return bool
     */
    public function onTwigSiteVariables()
    {
        // workaround for admin plugin issue that doesn't properly unsubscribe events upon plugin uninstall
        if (!class_exists(Helper::class)) {
            return false;
        }

        $user = $this->grav['user'];
        if (!$user->authenticated) {
            return false;
        }

        $settings = [
            'first_time'    => !Helper::isGitInitialized(),
            'git_installed' => Helper::isGitInstalled()
        ];

        $this->grav['twig']->twig_vars['git_sync'] = $settings;

        $adminPath = trim($this->grav['admin']->base, '/');
        if ($this->grav['uri']->path() === "/$adminPath/plugins/git-sync") {
            $this->grav['assets']->addCss('plugin://git-sync/css-compiled/git-sync.css');
        } else {
            $this->grav['assets']->addInlineJs('var GitSync = ' . json_encode($settings) . ';');
        }

        $this->grav['assets']->addJs('plugin://git-sync/js/vendor.js', ['loading' => 'defer', 'priority' => 0]);
        $this->grav['assets']->addJs('plugin://git-sync/js/app.js', ['loading' => 'defer', 'priority' => 0]);
        $this->grav['assets']->addCss('plugin://git-sync/css-compiled/git-sync-icon.css');

        return true;
    }

    public function onPageInitialized()
    {
        if ($this->controller && $this->controller->isActive()) {
            $this->controller->execute();
            $this->controller->redirect();
        }
    }

    /**
     * @param Event $event
     * @return Data|true
     */
    public function onAdminSave(Event $event)
    {
        $obj           = $event['object'];
        $adminPath 	   = trim($this->grav['admin']->base, '/');
        $isPluginRoute = $this->grav['uri']->path() === "/$adminPath/plugins/" . $this->name;

        if ($obj instanceof Data) {
            if (!$isPluginRoute || !Helper::isGitInstalled()) {
                return true;
            }

            // empty password, keep current one or encrypt if haven't already
            $password = $obj->get('password', false);
            if (!$password) { // set to !()
                $current_password = $this->git->getPassword();
                // password exists but was never encrypted
                if ($current_password && strpos($current_password, 'gitsync-') !== 0) {
                    $current_password = Helper::encrypt($current_password);
                }
            } else {
                // password is getting changed
                $current_password = Helper::encrypt($password);
            }

            $obj->set('password', $current_password);
        }

        return $obj;
    }

    /**
     * @param Event $event
     */
    public function onAdminAfterSave(Event $event)
    {
        $obj           = $event['object'];
        $adminPath	   = trim($this->grav['admin']->base, '/');
        $uriPath       = $this->grav['uri']->path();
        $isPluginRoute = $uriPath === "/$adminPath/plugins/" . $this->name;

        if ($obj instanceof PageInterface && !$this->grav['config']->get('plugins.git-sync.sync.on_save', true)) {
            return;
        }

        if ($obj instanceof Data) {
            $folders = $this->git->getConfig('folders', $event['object']->get('folders', []));
            $data_type = preg_replace('#^/' . preg_quote($adminPath, '#') . '/#', '', $uriPath);
            $data_type = explode('/', $data_type);
            $data_type = array_shift($data_type);

            if (null === $data_type || !Helper::isGitInstalled() || (!$isPluginRoute && !in_array($this->getFolderMapping($data_type), $folders, true))) {
                return;
            }

            if ($isPluginRoute) {
                $this->git->setConfig($obj->toArray());

                // initialize git if not done yet
                $this->git->initializeRepository();

                // set committer and remote data
                $this->git->setUser();
                $this->git->addRemote();
            }
        }

        $this->synchronize();
    }

    public function onAdminAfterSaveAs()
    {
        if ($this->grav['config']->get('plugins.git-sync.sync.on_save', true))
        {
            $this->synchronize();
        }
    }

    public function onAdminAfterDelete()
    {
        if ($this->grav['config']->get('plugins.git-sync.sync.on_delete', true))
        {
            $this->synchronize();
        }
    }

    public function onAdminAfterMedia()
    {
        if ($this->grav['config']->get('plugins.git-sync.sync.on_media', true))
        {
            $this->synchronize();
        }
    }

    /**
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $action = $event['action'];

        if ($action === 'gitsync') {
            $this->synchronize();
        }
    }

    /**
     * @param string $data_type
     * @return string|null
     */
    public function getFolderMapping($data_type)
    {
        switch ($data_type) {
            case 'user':
                return 'accounts';
            case 'themes':
                return 'config';
            case 'config':
            case 'data':
            case 'plugins':
            case 'pages':
                return $data_type;
        }

        return null;
    }

    /**
     * @param int $len
     * @return string
     */
    protected static function generateHash(int $len): string
    {
        $bytes = openssl_random_pseudo_bytes($len, $isStrong);

        if ($bytes === false) {
            throw new \RuntimeException('Could not generate hash');
        }

        if ($isStrong === false) {
            // It's ok not to be strong [EA].
            $isStrong = true;
        }

        return bin2hex($bytes);
    }
}
