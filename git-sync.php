<?php

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Plugin;
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
    protected $controller;
    protected $git;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized'   => ['onPluginsInitialized', 1000],
            'onPageInitialized'      => ['onPageInitialized', 0],
            'onFormProcessed'        => ['onFormProcessed', 0],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0]
        ];
    }

    /**
     * @return string
     */
    public static function generateWebhookSecret()
    {
        return bin2hex(openssl_random_pseudo_bytes(24));
    }

    /**
     * @return string
     */
    public static function generateRandomWebhook()
    {
        return '/_git-sync-' . bin2hex(openssl_random_pseudo_bytes(6));
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        require_once __DIR__ . '/vendor/autoload.php';
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
        } else {
            $config = $this->config->get('plugins.' . $this->name);
            $route = $this->grav['uri']->route();
            $webhook = isset($config['webhook']) ? $config['webhook'] : false;
            $secret = isset($config['webhook_secret']) ? $config['webhook_secret'] : false;
            $enabled = isset($config['webhook_enabled']) ? $config['webhook_enabled'] : false;

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
    }

    /**
     * Returns true if the request contains a valid signature or token
     * @param  string $secret local secret
     * @return boolean         whether or not the request is authorized
     */
    public function isRequestAuthorized($secret)
    {
        if (isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
            $payload = file_get_contents('php://input');
            return $this->isGithubSignatureValid($secret, $_SERVER['HTTP_X_HUB_SIGNATURE'], $payload);
        } elseif (isset($_SERVER['HTTP_X_GITLAB_TOKEN'])) {
            return $this->isGitlabTokenValid($secret, $_SERVER['HTTP_X_GITLAB_TOKEN']);
        }

        return false;
    }

    /**
     * Hashes the webhook request body with the client secret and
     * checks if it matches the webhook signature header
     * @param  string $secret The webhook secret
     * @param  string $signatureHeader The signature of the webhook request
     * @param  string $payload The webhook request body
     * @return boolean                 Whether the signature is valid or not
     */
    public function isGithubSignatureValid($secret, $signatureHeader, $payload)
    {
        list($algorigthm, $signature) = explode('=', $signatureHeader);

        if ($signature === hash_hmac($algorigthm, $payload, $secret)) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if given Gitlab token matches secret
     * @param  string $secret local secret
     * @param  string $token token received from Gitlab webhook request
     * @return boolean        whether or not secret and token match
     */
    public function isGitlabTokenValid($secret, $token)
    {
        return $secret === $token;
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
        } else {
            $this->controller      = new \stdClass;
            $this->controller->git = new GitSync($this);
        }

        $this->git = $this->controller->git;
    }

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
     */
    public function onTwigSiteVariables()
    {
        // workaround for admin plugin issue that doesn't properly unsubscribe
        // events upon plugin uninstall
        if (!class_exists('Grav\Plugin\GitSync\Helper')) {
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
        if ($this->isAdmin() && $this->controller->isActive()) {
            $this->controller->execute();
            $this->controller->redirect();
        }
    }

    public function onAdminSave($event)
    {
        $obj           = $event['object'];
        $adminPath 	   = trim($this->grav['admin']->base, '/');
        $isPluginRoute = $this->grav['uri']->path() == "/$adminPath/plugins/" . $this->name;

        if ($obj instanceof Data) {
            if (!$isPluginRoute || !Helper::isGitInstalled()) {
                return true;
            } else {
                // empty password, keep current one or encrypt if haven't already
                $password = $obj->get('password', false);
                if (!$password) { // set to !()
                    $current_password = $this->controller->git->getPassword();
                    // password exists but was never encrypted
                    if (substr($current_password, 0, 8) !== 'gitsync-') {
                        $current_password = Helper::encrypt($current_password);
                    }
                } else {
                    // password is getting changed
                    $current_password = Helper::encrypt($password);
                }

                $obj->set('password', $current_password);
            }
        }

        return $obj;
    }

    public function onAdminAfterSave($event)
    {
        if (!$this->grav['config']->get('plugins.git-sync.sync.on_save', true)) {
            return true;
        }

        $obj           = $event['object'];
        $adminPath	   = trim($this->grav['admin']->base, '/');
        $uriPath       = $this->grav['uri']->path();
        $isPluginRoute = $uriPath == "/$adminPath/plugins/" . $this->name;

        if ($obj instanceof Data) {
            $folders = $this->controller->git->getConfig('folders', $event['object']->get('folders', []));
            $data_type = preg_replace('#^/' . preg_quote($adminPath, '#') . '/#', '', $uriPath);
            $data_type = explode('/', $data_type);
            $data_type = array_shift($data_type);

            if (!Helper::isGitInstalled() || (!$isPluginRoute && !in_array($this->getFolderMapping($data_type), $folders))) {
                return true;
            }

            if ($isPluginRoute) {
                $this->controller->git->setConfig($obj);

                // initialize git if not done yet
                $this->controller->git->initializeRepository();

                // set committer and remote data
                $this->controller->git->setUser();
                $this->controller->git->addRemote();
            }
        }

        $this->synchronize();

        return true;
    }

    public function onAdminAfterSaveAs()
    {
        if ($this->grav['config']->get('plugins.git-sync.sync.on_save', true)) {
            $this->synchronize();
        }

        return true;
    }

    public function onAdminAfterDelete()
    {
        if ($this->grav['config']->get('plugins.git-sync.sync.on_delete', true)) {
            $this->synchronize();
        }

        return true;
    }

    public function onAdminAfterMedia()
    {
        if ($this->grav['config']->get('plugins.git-sync.sync.on_media', true)) {
            $this->synchronize();
        }

        return true;
    }

    public function onFormProcessed(Event $event)
    {
        $action = $event['action'];

        if ($action == 'gitsync') {
            $this->synchronize();
        }
    }

    public function getFolderMapping($data_type) {
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
    }
}
