<?php

declare(strict_types=1);

namespace Grav\Plugin\GitSync\Api;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Plugins;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\GitSync\GitSync;
use Grav\Plugin\GitSync\Helper;
use Grav\Plugin\GitSyncPlugin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin-Next API controller for git-sync.
 *
 * Endpoints back the blueprint-mode plugin settings page plus the wizard
 * modal hosted by the auto-loaded floating widget script. Settings are
 * persisted to config://plugins/git-sync.yaml — the same file admin-classic
 * reads/writes — so the two admins stay interchangeable.
 */
class GitSyncApiController extends AbstractApiController
{
    private function requireGitSyncPermission(ServerRequestInterface $request, string $level): void
    {
        $user = $this->getUser($request);

        if ($this->isSuperAdmin($user)) {
            return;
        }

        if (!$this->hasPermission($user, 'api.access')) {
            throw new ForbiddenException('API access is not enabled for this user.');
        }

        $required = $level === 'write'
            ? ['api.git-sync', 'api.git-sync.write', 'api.git-sync.admin']
            : ['api.git-sync', 'api.git-sync.read', 'api.git-sync.write', 'api.git-sync.admin'];

        foreach ($required as $perm) {
            if ($this->hasPermission($user, $perm)) {
                return;
            }
        }

        throw new ForbiddenException("Missing required Git Sync '{$level}' permission");
    }

    /**
     * GET /git-sync/data — current settings for the plugin form.
     *
     * The raw encrypted password never leaves the server — we only signal
     * whether one is stored, so the enc-password field can show its
     * "securely stored" placeholder.
     */
    public function data(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireGitSyncPermission($request, 'read');

        $cfg = (array) $this->config->get('plugins.git-sync', []);
        $sync = (array) ($cfg['sync'] ?? []);
        $remote = (array) ($cfg['remote'] ?? []);
        $git = (array) ($cfg['git'] ?? []);

        return ApiResponse::create([
            'enabled'           => (bool) ($cfg['enabled'] ?? false),
            'folders'           => array_values((array) ($cfg['folders'] ?? ['pages'])),
            'local_repository'  => (string) ($cfg['local_repository'] ?? ''),
            'repository'        => (string) ($cfg['repository'] ?? ''),
            'no_user'           => (bool) ($cfg['no_user'] ?? false),
            'user'              => (string) ($cfg['user'] ?? ''),
            // Form binds an empty string by default; server keeps the existing
            // password unless the user types a new one. Storage state is
            // exposed on the resolved blueprint (see onApiBlueprintResolved)
            // so the enc-password component can render the right placeholder.
            'password'          => '',
            'webhook'           => (string) ($cfg['webhook'] ?? ''),
            'webhook_enabled'   => (bool) ($cfg['webhook_enabled'] ?? false),
            'webhook_secret'    => (string) ($cfg['webhook_secret'] ?? ''),
            'branch'            => (string) ($cfg['branch'] ?? 'master'),
            'logging'           => (bool) ($cfg['logging'] ?? false),
            'sync'              => [
                'direction'   => (string) ($sync['direction'] ?? 'both'),
                'on_save'     => (bool) ($sync['on_save'] ?? true),
                'on_delete'   => (bool) ($sync['on_delete'] ?? true),
                'on_media'    => (bool) ($sync['on_media'] ?? true),
                'cron_enable' => (bool) ($sync['cron_enable'] ?? false),
                'cron_at'     => (string) ($sync['cron_at'] ?? '0 12,23 * * *'),
            ],
            'remote'            => [
                'name'   => (string) ($remote['name'] ?? 'origin'),
                'branch' => (string) ($remote['branch'] ?? 'master'),
            ],
            'git'               => [
                'author'      => (string) ($git['author'] ?? 'gituser'),
                'message'     => (string) ($git['message'] ?? '(Grav GitSync) Automatic Commit'),
                'name'        => (string) ($git['name'] ?? 'GitSync'),
                'email'       => (string) ($git['email'] ?? 'git-sync@trilby.media'),
                'bin'         => (string) ($git['bin'] ?? 'git'),
                'ignore'      => (string) ($git['ignore'] ?? ''),
                'private_key' => (string) ($git['private_key'] ?? ''),
            ],
        ]);
    }

    /**
     * PATCH /git-sync/data — persist plugin settings.
     *
     * Mirrors the admin-classic onAdminSave password handling: if the form
     * sends an empty password we keep whatever is currently stored (and
     * encrypt it if it was somehow saved in plaintext); a non-empty value
     * gets encrypted before write.
     */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireGitSyncPermission($request, 'write');

        $body = $this->getRequestBody($request);
        $existing = (array) $this->config->get('plugins.git-sync', []);
        $merged = $existing;

        // Top-level scalars / lists
        foreach (['enabled', 'folders', 'local_repository', 'repository', 'no_user',
                  'user', 'webhook', 'webhook_enabled', 'webhook_secret', 'branch', 'logging'] as $key) {
            if (array_key_exists($key, $body)) {
                $merged[$key] = $body[$key];
            }
        }

        // Password — empty means "keep existing", non-empty means "encrypt & replace"
        $newPassword = $body['password'] ?? null;
        if ($newPassword === null || $newPassword === '') {
            $current = (string) ($existing['password'] ?? '');
            if ($current !== '' && !str_starts_with($current, 'gitsync-')) {
                $merged['password'] = Helper::encrypt($current);
            } else {
                $merged['password'] = $current;
            }
        } else {
            $merged['password'] = Helper::encrypt((string) $newPassword);
        }

        // Nested: sync / remote / git
        foreach (['sync', 'remote', 'git'] as $section) {
            if (isset($body[$section]) && is_array($body[$section])) {
                $merged[$section] = array_merge((array) ($merged[$section] ?? []), $body[$section]);
            }
        }

        // Auto-generate webhook / webhook_secret if blank, matching admin-classic's data-default@
        if (empty($merged['webhook'])) {
            $merged['webhook'] = GitSyncPlugin::generateRandomWebhook();
        }
        if (empty($merged['webhook_secret'])) {
            $merged['webhook_secret'] = GitSyncPlugin::generateWebhookSecret();
        }

        $this->writePluginConfig($merged);

        // Mirror admin-classic onAdminAfterSave: initialize repo / set remote
        // when the plugin page form is saved with a configured repository.
        if (Helper::isGitInstalled() && Helper::isGitSyncConfigured()) {
            try {
                $git = new GitSync();
                $git->setConfig($merged);
                $git->initializeRepository();
                $git->setUser();
                $git->addRemote();
            } catch (\Throwable $e) {
                // Don't fail the save — surface as a warning in the response.
                return ApiResponse::create([
                    'message' => 'Settings saved, but repository setup ran into an issue: '
                        . Helper::preventReadablePassword($e->getMessage(), $merged['password'] ?? ''),
                ]);
            }
        }

        return ApiResponse::create([
            'message' => 'Git Sync settings saved.',
        ]);
    }

    /**
     * POST /git-sync/sync — synchronize with the remote repository.
     */
    public function sync(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireGitSyncPermission($request, 'write');

        if (!Helper::isGitInstalled()) {
            throw new ValidationException('Git is not installed or not on the configured PATH.');
        }
        if (!Helper::isGitSyncReady()) {
            throw new ValidationException('Git Sync is not configured yet — run the Wizard first.');
        }

        @set_time_limit(0);
        // Release the PHP session lock so the rest of admin-next stays
        // responsive while the network-bound git pull/push finishes.
        // Without this, every concurrent request from the same browser
        // blocks behind this one and the UI feels frozen.
        @session_write_close();

        try {
            $plugin = $this->getGitSyncPlugin();
            $plugin->synchronize();
        } catch (\Throwable $e) {
            $password = (string) ($this->config->get('plugins.git-sync.password') ?? '');
            throw new ValidationException(
                Helper::preventReadablePassword($e->getMessage(), $password)
            );
        }

        return ApiResponse::create([
            'message' => 'Git Sync has successfully synchronized with the repository.',
        ]);
    }

    /**
     * POST /git-sync/reset — discard local changes (git reset --hard HEAD).
     */
    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireGitSyncPermission($request, 'write');

        if (!Helper::isGitInstalled()) {
            throw new ValidationException('Git is not installed or not on the configured PATH.');
        }
        if (!Helper::isGitSyncReady()) {
            throw new ValidationException('Git Sync is not configured yet — run the Wizard first.');
        }

        @set_time_limit(0);
        @session_write_close();

        try {
            $plugin = $this->getGitSyncPlugin();
            $plugin->reset();
        } catch (\Throwable $e) {
            $password = (string) ($this->config->get('plugins.git-sync.password') ?? '');
            throw new ValidationException(
                Helper::preventReadablePassword($e->getMessage(), $password)
            );
        }

        return ApiResponse::create([
            'message' => 'Git Sync has reset your local copy and re-synchronized with the repository.',
        ]);
    }

    /**
     * POST /git-sync/test-connection — wizard "Verify Authentication, Connection and Branch".
     *
     * Body: { user, password, repository, branch, no_user }
     *
     * Mirrors AdminController::taskTestConnection. The credentials are NOT
     * persisted — they exist only for the duration of this ls-remote probe.
     */
    public function testConnection(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireGitSyncPermission($request, 'write');

        if (!Helper::isGitInstalled()) {
            throw new ValidationException('Git is not installed or not on the configured PATH.');
        }

        $body = $this->getRequestBody($request);
        $user = (string) ($body['user'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $repository = (string) ($body['repository'] ?? '');
        $branch = (string) ($body['branch'] ?? '');
        $noUser = (bool) ($body['no_user'] ?? false);

        if ($repository === '') {
            throw new ValidationException('Repository URL is required.');
        }
        if ($branch === '') {
            throw new ValidationException('Branch is required.');
        }
        if ($noUser) {
            $user = '';
        }

        try {
            $result = Helper::testRepository($user, $password, $repository, $branch);
        } catch (\Throwable $e) {
            $message = str_replace($password, '{password}', $e->getMessage());
            return ApiResponse::create([
                'status'  => 'error',
                'message' => $message,
            ]);
        }

        if (empty($result)) {
            return ApiResponse::create([
                'status'  => 'error',
                'message' => "Branch \"{$branch}\" not found in the repository.",
            ]);
        }

        return ApiResponse::create([
            'status'  => 'success',
            'message' => 'Connection to the repository was successful.',
        ]);
    }

    /**
     * GET /git-sync/wizard/state — pre-flight + current settings for the wizard.
     *
     * The wizard reuses the saved repo / branch / webhook to pre-fill its
     * inputs the second time around (admin-classic does the same via Twig).
     */
    public function wizardState(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireGitSyncPermission($request, 'read');

        $cfg = (array) $this->config->get('plugins.git-sync', []);
        $password = (string) ($cfg['password'] ?? '');

        // Compute the public site URL the way Twig admin-classic does it
        // (`uri.base ~ uri.rootUrl`). The browser-side `window.location.origin`
        // alone misses Grav installs that live in a sub-folder, leaving the
        // wizard's webhook URL preview wrong. Trailing slash trimmed so the
        // client can append the webhook path cleanly.
        $uri = $this->grav['uri'];
        $frontendUrl = rtrim($uri->base() . $uri->rootUrl(), '/');

        return ApiResponse::create([
            'git_installed'   => (bool) Helper::isGitInstalled(),
            'git_initialized' => (bool) Helper::isGitInitialized(),
            'configured'      => (bool) Helper::isGitSyncConfigured(),
            'frontend_url'    => $frontendUrl,
            'settings'        => [
                'repository'      => (string) ($cfg['repository'] ?? ''),
                'no_user'         => (bool) ($cfg['no_user'] ?? false),
                'user'            => (string) ($cfg['user'] ?? ''),
                'password_stored' => $password !== '',
                'branch'          => (string) ($cfg['branch'] ?? ''),
                'webhook'         => (string) ($cfg['webhook'] ?? GitSyncPlugin::generateRandomWebhook()),
                'webhook_enabled' => (bool) ($cfg['webhook_enabled'] ?? false),
                'webhook_secret'  => (string) ($cfg['webhook_secret'] ?? GitSyncPlugin::generateWebhookSecret()),
                'folders'         => array_values((array) ($cfg['folders'] ?? ['pages'])),
            ],
        ]);
    }

    /**
     * Resolve the live GitSyncPlugin instance.
     *
     * `$grav['plugins']->get('git-sync')` returns a Data wrapper around the
     * blueprint, not the plugin instance — this fetches the actual plugin
     * via Plugins::getPlugin(), which is what we need to call synchronize()
     * and reset().
     */
    private function getGitSyncPlugin(): GitSyncPlugin
    {
        $plugin = Plugins::getPlugin('git-sync');
        if (!$plugin instanceof GitSyncPlugin) {
            throw new ValidationException('Git Sync plugin is not loaded.');
        }
        return $plugin;
    }

    private function writePluginConfig(array $data): void
    {
        $locator = $this->grav['locator'];
        $pluginsDir = $locator->findResource('config://plugins', true, true);
        if (!$pluginsDir) {
            throw new ValidationException('Could not resolve config://plugins directory.');
        }
        if (!is_dir($pluginsDir)) {
            @mkdir($pluginsDir, 0775, true);
        }

        $file = CompiledYamlFile::instance($pluginsDir . '/git-sync.yaml');
        $file->save($data);
        $file->free();

        $this->config->set('plugins.git-sync', $data);

        $cache = $this->grav['cache'] ?? null;
        if ($cache && method_exists($cache, 'clearCache')) {
            $cache->clearCache('standard');
        }
    }
}
