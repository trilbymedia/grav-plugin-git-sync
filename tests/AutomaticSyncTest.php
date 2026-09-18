<?php

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Flex\Types\Generic\GenericObject;
use Grav\Plugin\GitSync\GitSync;
use Grav\Plugin\GitSyncPlugin;
use PHPUnit\Framework\TestCase;
use RocketTheme\Toolbox\Event\Event;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Run with Grav's PHPUnit and autoloader; no repository or remote is contacted.
class AutomaticSyncTest extends TestCase
{
    public function testSaveSwitchAppliesToPagesAndFlexObjects(): void
    {
        foreach ([false, true] as $enabled) {
            foreach ([(new ReflectionClass(Page::class))->newInstanceWithoutConstructor(), (new ReflectionClass(GenericObject::class))->newInstanceWithoutConstructor()] as $object) {
                $grav = new Grav();
                $grav['config'] = new Config(['plugins' => ['git-sync' => ['sync' => ['on_save' => $enabled]]]]);
                $plugin = new AutomaticSyncProbe('git-sync', $grav);
                $plugin->onAdminAfterSave(new Event(['object' => $object]));
                self::assertSame($enabled ? 1 : 0, $plugin->syncs);
                $plugin->onAdminAfterSaveAs();
                self::assertSame($enabled ? 2 : 0, $plugin->syncs);
            }
        }
    }

    public function testManualSyncRemainsAvailableWithAllAutomaticTriggersDisabled(): void
    {
        $grav = new Grav();
        $grav['config'] = new Config(['plugins' => ['git-sync' => ['sync' => [
            'on_save' => false, 'on_delete' => false, 'on_media' => false, 'cron_enable' => false
        ]]]]);
        $plugin = new AutomaticSyncProbe('git-sync', $grav);
        $event = new Event(['object' => (new ReflectionClass(GenericObject::class))->newInstanceWithoutConstructor()]);
        $plugin->onAdminAfterSave($event);
        $plugin->onAdminAfterDelete($event);
        $plugin->onAdminAfterMedia($event);
        self::assertSame(0, $plugin->syncs);
        $plugin->synchronize();
        self::assertSame(1, $plugin->syncs);
    }
}

class AutomaticSyncProbe extends GitSyncPlugin
{
    public $syncs = 0;

    public function __construct($name, Grav $grav)
    {
        parent::__construct($name, $grav);
        $this->git = (new ReflectionClass(GitSync::class))->newInstanceWithoutConstructor();
    }

    public function synchronize()
    {
        $this->syncs++;
        return true;
    }
}
