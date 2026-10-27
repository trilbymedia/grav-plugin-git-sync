<?php

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\GitSync\GitSync;
use Grav\Plugin\GitSyncPlugin;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Run with Grav's PHPUnit and autoloader; no repository or remote is contacted.
class WebhookTest extends TestCase
{
    private const HEADERS = [
        'HTTP_X_HUB_SIGNATURE', 'HTTP_X_HUB_SIGNATURE_256', 'HTTP_X_GITEA_SIGNATURE',
        'HTTP_X_GOGS_SIGNATURE', 'HTTP_X_GITLAB_TOKEN',
    ];

    protected function tearDown(): void
    {
        foreach (self::HEADERS as $header) {
            unset($_SERVER[$header]);
        }
    }

    private function plugin(array $config): WebhookProbe
    {
        $grav = new Grav();
        $grav['config'] = new Config(['plugins' => ['git-sync' => $config]]);

        return new WebhookProbe('git-sync', $grav, $grav['config']);
    }

    public function testConfiguredPathVariantsAllMatchTheRoute(): void
    {
        foreach (['/_git-sync-abc', '_git-sync-abc', '/_git-sync-abc/', ' /_git-sync-abc ', 'https://example.com/_git-sync-abc'] as $webhook) {
            self::assertSame('/_git-sync-abc', GitSyncPlugin::normalizeWebhookPath($webhook), $webhook);
        }
        // Sub-folder install: the full URL from the wizard carries the base path.
        self::assertSame('/_git-sync-abc', GitSyncPlugin::normalizeWebhookPath('https://example.com/blog/_git-sync-abc', '/blog'));
        self::assertSame('/_git-sync-abc', GitSyncPlugin::normalizeWebhookPath('/blog/_git-sync-abc/', '/blog'));
        self::assertSame('/blogroll/hook', GitSyncPlugin::normalizeWebhookPath('/blogroll/hook', '/blog'));
        // Never the home page.
        foreach (['', '/', '  ', 'https://example.com/', '/blog'] as $webhook) {
            self::assertSame('', GitSyncPlugin::normalizeWebhookPath($webhook, '/blog'), var_export($webhook, true));
        }
    }

    public function testGitlabCallSynchronizes(): void
    {
        $plugin = $this->plugin(['webhook' => '_git-sync-abc/', 'webhook_enabled' => true, 'webhook_secret' => 's3cr3t']);

        $_SERVER['HTTP_X_GITLAB_TOKEN'] = 's3cr3t';
        self::assertSame(200, $plugin->handleWebhookRequest('/_git-sync-abc', 'POST')[0]);
        self::assertSame(1, $plugin->syncs);

        $_SERVER['HTTP_X_GITLAB_TOKEN'] = 'wrong';
        self::assertSame(401, $plugin->handleWebhookRequest('/_git-sync-abc', 'POST')[0]);
        self::assertSame(1, $plugin->syncs);
    }

    public function testDisabledWebhookSaysSoInsteadOfFallingThroughToPageNotFound(): void
    {
        $plugin = $this->plugin(['webhook' => '/_git-sync-abc', 'webhook_enabled' => false, 'webhook_secret' => 's3cr3t']);
        $_SERVER['HTTP_X_GITLAB_TOKEN'] = 's3cr3t';

        self::assertSame(403, $plugin->handleWebhookRequest('/_git-sync-abc', 'POST')[0]);
        self::assertSame(0, $plugin->syncs);
    }

    public function testOtherRoutesAndMethods(): void
    {
        $plugin = $this->plugin(['webhook' => '/_git-sync-abc', 'webhook_enabled' => true, 'webhook_secret' => 's3cr3t']);

        self::assertNull($plugin->handleWebhookRequest('/some-page', 'POST'));
        self::assertSame(405, $plugin->handleWebhookRequest('/_git-sync-abc', 'GET')[0]);
        self::assertNull($this->plugin(['webhook_enabled' => true])->handleWebhookRequest('/', 'POST'));
        self::assertSame(0, $plugin->syncs);
    }

    public function testSignatureHeaders(): void
    {
        $plugin = $this->plugin([]);
        $body = '{"zen":"Keep it logically awesome."}';
        $sha256 = hash_hmac('sha256', $body, 's3cr3t');

        // GitHub and Bitbucket Cloud, SHA-256 header only.
        $_SERVER['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . $sha256;
        self::assertTrue($plugin->isRequestAuthorized('s3cr3t', $body));
        self::assertFalse($plugin->isRequestAuthorized('other', $body));

        // GitHub also sending the legacy SHA-1 header.
        $_SERVER['HTTP_X_HUB_SIGNATURE'] = 'sha1=' . hash_hmac('sha1', $body, 's3cr3t');
        self::assertTrue($plugin->isRequestAuthorized('s3cr3t', $body));
        $this->tearDown();

        // Gitea / Forgejo bare hex digest.
        $_SERVER['HTTP_X_GITEA_SIGNATURE'] = $sha256;
        self::assertTrue($plugin->isRequestAuthorized('s3cr3t', $body));
        self::assertFalse($plugin->isRequestAuthorized('s3cr3t', $body . ' '));
    }
}

class WebhookProbe extends GitSyncPlugin
{
    public $syncs = 0;

    public function __construct($name, Grav $grav, ?Config $config = null)
    {
        parent::__construct($name, $grav, $config);
        $this->git = (new ReflectionClass(GitSync::class))->newInstanceWithoutConstructor();
    }

    public function synchronize()
    {
        $this->syncs++;
        return true;
    }
}
