<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Config\Config;
use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Common\Scheduler\Scheduler;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
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
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],

            // Admin-Next (API plugin) integration
            'onApiRegisterRoutes'    => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems'      => ['onApiSidebarItems', 0],
            'onApiMenubarItems'      => ['onApiMenubarItems', 0],
            'onApiMenubarAction'     => ['onApiMenubarAction', 0],
            'onApiPluginPageInfo'    => ['onApiPluginPageInfo', 0],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
            'onApiFloatingWidgets'   => ['onApiFloatingWidgets', 0],
            PermissionsRegisterEvent::class => [
                ['onRegisterPermissions', 100],
            ],
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

        // Auto-sync triggers — page save / delete / media events.
        //
        // These need to be subscribed regardless of context because the API
        // plugin (admin-next backend) registers its AdminProxy AFTER
        // onPluginsInitialized has already fired, so an isAdmin() check
        // at boot misses every API-driven save / delete / media event.
        // The handlers themselves gate internally on object type and
        // admin path, and the events simply never fire on the frontend
        // or in CLI, so registering them globally is safe.
        $this->enable([
            'onAdminSave'          => ['onAdminSave', 0],
            'onAdminAfterSave'     => ['onAdminAfterSave', 0],
            'onAdminAfterSaveAs'   => ['onAdminAfterSaveAs', 0],
            'onAdminAfterDelete'   => ['onAdminAfterDelete', 0],
            'onAdminAfterAddMedia' => ['onAdminAfterMedia', 0],
            'onAdminAfterDelMedia' => ['onAdminAfterMedia', 0],
        ]);

        // Admin-classic-only subs (Twig assets, sidebar entry, quick-tray button).
        if ($this->isAdmin()) {
            $this->enable([
                'onTwigTemplatePaths'  => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables'  => ['onTwigSiteVariables', 0],
                'onAdminMenu'          => ['onAdminMenu', 0],
            ]);

            return;
        }

        $config = $this->config->get('plugins.' . $this->name);
        $route = $this->grav['uri']->route();
        $webhook = $config['webhook'] ?? false;
        $secret = $config['webhook_secret'] ?? false;
        $enabled = $config['webhook_enabled'] ?? false;

        if ($enabled && $route === $webhook && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($secret) {
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

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\GitSync\Api\GitSyncApiController::class;

        $routes->group('/git-sync', function ($group) use ($controller) {
            $group->get('/data', [$controller, 'data']);
            $group->patch('/data', [$controller, 'save']);
            $group->post('/sync', [$controller, 'sync']);
            $group->post('/reset', [$controller, 'reset']);
            $group->post('/test-connection', [$controller, 'testConnection']);
            $group->get('/wizard/state', [$controller, 'wizardState']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'git-sync',
            'plugin'    => 'git-sync',
            'label'     => 'Git Sync',
            'icon'      => 'fa-code-branch',
            'route'     => '/plugin/git-sync',
            'priority'  => 5,
            // Match the read-level any-of check in GitSyncApiController:
            // anyone with read / write / admin (or the parent) sees the item.
            'authorize' => ['api.git-sync', 'api.git-sync.read', 'api.git-sync.write', 'api.git-sync.admin'],
        ];
        $event['items'] = $items;
    }

    public function onApiMenubarItems(Event $event): void
    {
        if (!Helper::isGitInstalled() || !Helper::isGitSyncReady()) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'git-sync-quick',
            'plugin'    => 'git-sync',
            'label'     => 'Synchronize Git Sync',
            'icon'      => 'fa-code-branch',
            'action'    => 'sync',
            // Sync is a write action — only show the menubar button to users
            // who can actually run it.
            'authorize' => ['api.git-sync', 'api.git-sync.write', 'api.git-sync.admin'],
        ];
        $event['items'] = $items;
    }

    public function onApiMenubarAction(Event $event): void
    {
        if ($event['plugin'] !== 'git-sync') {
            return;
        }

        if ($event['action'] === 'sync') {
            // Release the session lock so the rest of admin-next stays
            // responsive while git pull/push runs over the network.
            @set_time_limit(0);
            @session_write_close();
            try {
                $this->synchronize();
                $event['result'] = [
                    'status'  => 'success',
                    'message' => 'GitSync has successfully synchronized with the repository.',
                ];
            } catch (\Throwable $e) {
                $event['result'] = [
                    'status'  => 'error',
                    'message' => Helper::preventReadablePassword($e->getMessage(), $this->git ? $this->git->getPassword() ?? '' : ''),
                ];
            }
        }
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'git-sync') {
            return;
        }

        $event['definition'] = [
            'id'            => 'git-sync',
            'plugin'        => 'git-sync',
            'title'         => 'Git Sync',
            'icon'          => 'fa-code-branch',
            'page_type'     => 'blueprint',
            'blueprint'     => 'git-sync',
            'data_endpoint' => '/git-sync/data',
            'save_endpoint' => '/git-sync/data',
            'actions'       => [
                [
                    'id'    => 'wizard',
                    'label' => 'Wizard',
                    'icon'  => 'fa-wand-magic-sparkles',
                    // No endpoint — admin-next dispatches grav:plugin-page-action
                    // and the auto-loaded git-sync widget script catches it.
                ],
                [
                    'id'       => 'sync',
                    'label'    => 'Synchronize',
                    'icon'     => 'fa-cloud-arrow-up',
                    'endpoint' => '/git-sync/sync',
                ],
                [
                    'id'       => 'reset',
                    'label'    => 'Reset Local Copy',
                    'icon'     => 'fa-clock-rotate-left',
                    'endpoint' => '/git-sync/reset',
                    'confirm'  => 'Discard all local changes and re-pull from the remote? Any uncommitted edits will be lost.',
                ],
                [
                    'id'      => 'save',
                    'label'   => 'Save',
                    'icon'    => 'fa-check',
                    'primary' => true,
                ],
            ],
        ];
    }

    /**
     * Strip admin-classic-only fields from the blueprint sent to admin-next
     * and annotate the enc-password field with current stored/encrypted state
     * so its custom component can render the right placeholder.
     *
     * The wizard / sync / reset buttons are now header actions; the in-form
     * `_wizard` field and its surrounding `Actions` section have nothing to
     * render in admin-next. The YAML stays intact for admin-classic.
     */
    public function onApiBlueprintResolved(Event $event): void
    {
        $context = $event['context'] ?? null;
        if ($context !== 'plugin' && $context !== 'plugin-page') {
            return;
        }
        if (($event['plugin'] ?? null) !== 'git-sync') {
            return;
        }

        // Generic Plugins → Git Sync detail page collapses to just an
        // enable / disable toggle plus a pointer to the dedicated page.
        // The full form + Wizard / Sync / Reset actions live at
        // /admin/plugin/git-sync (the sidebar entry), so the two pages
        // don't overlap.
        if ($context === 'plugin') {
            $event['fields'] = [
                [
                    'name'     => 'admin_next_notice',
                    'type'     => 'display',
                    'markdown' => true,
                    'content'  => "**Git Sync** has its own admin page with the full configuration form, the setup Wizard, and the Synchronize / Reset actions. Open it from the **Git Sync** entry in the sidebar.",
                ],
                [
                    'name'      => 'enabled',
                    'type'      => 'toggle',
                    'label'     => 'Plugin Status',
                    'highlight' => 1,
                    'default'   => 0,
                    'options'   => [
                        ['value' => '1', 'label' => 'Enabled'],
                        ['value' => '0', 'label' => 'Disabled'],
                    ],
                    'validate'  => ['type' => 'bool'],
                ],
            ];
            return;
        }

        // Dedicated plugin page (context: plugin-page) — strip the
        // admin-classic-only wizard launcher + its Actions section, and
        // annotate the password field with current storage state for the
        // enc-password component.
        $stored = (string) ($this->config->get('plugins.git-sync.password') ?? '');
        $isStored = $stored !== '';
        $isEncrypted = $isStored && str_starts_with($stored, 'gitsync-');

        $fields = $event['fields'];
        $filtered = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            $type = $field['type'] ?? '';

            if ($name === 'Actions' || $name === '_wizard' || $type === 'git-wizard') {
                continue;
            }

            if ($name === 'password') {
                $field['password_stored'] = $isStored;
                $field['password_encrypted'] = $isEncrypted;
            }

            $filtered[] = $field;
        }
        $event['fields'] = $filtered;
    }

    /**
     * Register the wizard host as an auto-loaded floating widget with no FAB.
     *
     * The widget panel is never opened by the user — the script just needs to
     * be loaded so its top-level event listener can catch the `wizard` action
     * dispatched from the plugin page header and render the modal as a portal.
     */
    public function onApiFloatingWidgets(Event $event): void
    {
        $widgets = $event['widgets'] ?? [];
        $widgets[] = [
            'id'       => 'git-sync',
            'plugin'   => 'git-sync',
            'label'    => 'Git Sync Wizard',
            'icon'     => 'fa-wand-magic-sparkles',
            'autoLoad' => true,
            'showFab'  => false,
        ];
        $event['widgets'] = $widgets;
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $permissions = $event->permissions;
        $actions = PermissionsReader::fromYaml('plugin://git-sync/permissions.yaml');
        $permissions->addActions($actions);
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
        // Skip if git-sync is not properly configured
        if (!Helper::isGitSyncReady()) {
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
        // Skip if git-sync is not properly configured
        if (!Helper::isGitSyncReady()) {
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

                // Only initialize repository if properly configured
                if (Helper::isGitSyncConfigured()) {
                    // initialize git if not done yet
                    $this->git->initializeRepository();

                    // set committer and remote data
                    $this->git->setUser();
                    $this->git->addRemote();
                }
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
