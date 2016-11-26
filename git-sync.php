<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\GitSync\AdminController;
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

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPageInitialized'    => ['onPageInitialized', 0],
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            require_once __DIR__ . '/vendor/autoload.php';

            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            ]);

            /** @var AdminController controller */
            $this->controller = new AdminController($this);

            return;
        }
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
        // $paths = $this->config->get('plugins.git-sync.folders', ['user/pages']);
        $settings = [
            'first_time' => !Helper::isGitInitialized(),
        ];

        $this->grav['twig']->twig_vars['git_sync'] = $settings;

        if ($this->grav['uri']->path() === '/admin/plugins/git-sync') {
            $this->grav['assets']->addCss('plugin://git-sync/css/git-sync.css');
            $this->grav['assets']->addJs('plugin://git-sync/js/app.js', ['loading' => 'defer']);
        }
    }

    public function onPageInitialized()
    {
        if ($this->controller->isActive()) {
            $this->controller->execute();
            $this->controller->redirect();
        }
    }
}
