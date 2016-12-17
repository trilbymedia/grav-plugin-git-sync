<?php
namespace Grav\Plugin;

use Grav\Common\Data\Data;
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
            'onPluginsInitialized' => ['onPluginsInitialized', 1000],
            'onPageInitialized'    => ['onPageInitialized', 0],
            'onFormProcessed'      => ['onFormProcessed', 0]
        ];
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
                'onAdminAfterSave'     => ['onAdminAfterSave', 0],
                'onAdminAfterSaveAs'   => ['synchronize', 0],
                'onAdminAfterDelete'   => ['synchronize', 0],
                'onAdminAfterAddMedia' => ['synchronize', 0],
                'onAdminAfterDelMedia' => ['synchronize', 0],
            ]);

            return;
        } else {
            $config  = $this->config->get('plugins.' . $this->name);
            $route   = $this->grav['uri']->route();
            $webhook = isset($config['webhook']) ? $config['webhook'] : false;

            if ($route === $webhook) {
                try {
                    $this->synchronize();

                    echo json_encode([
                        'status'  => 'success',
                        'message' => 'GitSync completed the synchronization'
                    ]);
                } catch (\Exception $e) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'GitSync failed to synchronize'
                    ]);
                }
                exit;
            }
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

        if (!$this->git->isWorkingCopyClean()) {
            // commit any change
            $this->git->commit();
        }

        // synchronize with remote
        $this->git->sync();

        $this->grav->fireEvent('onGitSyncAfterSynchronize');

        return true;
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
        $settings = [
            'first_time'    => !Helper::isGitInitialized(),
            'git_installed' => Helper::isGitInstalled()
        ];

        $this->grav['twig']->twig_vars['git_sync'] = $settings;

        if ($this->grav['uri']->path() === '/admin/plugins/git-sync') {
            $this->grav['assets']->addCss('plugin://git-sync/css/git-sync.css');
            $this->grav['assets']->addJs('plugin://git-sync/js/app.js', ['loading' => 'defer']);
        }
    }

    public function onPageInitialized()
    {
        if ($this->isAdmin() && $this->controller->isActive()) {
            $this->controller->execute();
            $this->controller->redirect();
        }


    }

    public function onAdminAfterSave($event)
    {
        $obj           = $event['object'];
        $isPluginRoute = $this->grav['uri']->path() == '/admin/plugins/' . $this->name;

        /*
        $folders = $this->controller->git->getConfig('folders', []);
        if (!$isPluginRoute && !in_array('config', $folders)) {
            return true;
        }
        */

        if ($obj instanceof Data) {
            if (!$isPluginRoute || !Helper::isGitInstalled()) {
                return true;
            } else {
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

    public function onFormProcessed(Event $event)
    {
        $action = $event['action'];

        if ($action == 'gitsync') {
            $this->synchronize();
        }
    }
}
