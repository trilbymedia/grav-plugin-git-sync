<?php
namespace Grav\Plugin\GitSync;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
use http\Exception\RuntimeException;
use RocketTheme\Toolbox\File\File;
use SebastianBergmann\Git\Git;

class GitSync extends Git
{
    /** @var static */
    static public $instance;

    /** @var Grav */
    protected $grav;
    /** @var Plugin */
    protected $plugin;
    /** @var array */
    protected $config;
    /** @var string */
    protected $repositoryPath;
    /** @var PageInterface|null Page behind the change being committed, if any */
    protected $page;

    /** @var string|null */
    private $user;
    /** @var string|null */
    private $password;

    public function __construct()
    {
        $this->grav = Grav::instance();
        $this->config = $this->grav['config']->get('plugins.git-sync') ?? [];
        $this->repositoryPath = isset($this->config['local_repository']) && $this->config['local_repository'] ? $this->config['local_repository'] : USER_DIR;

        parent::__construct($this->repositoryPath);

        static::$instance = $this;

        $this->user = isset($this->config['no_user']) && $this->config['no_user'] ? '' : ($this->config['user'] ?? null);
        $this->password = $this->config['password'] ?? null;

        unset($this->config['user'], $this->config['password']);
    }

    /**
     * @return static
     */
    public static function instance()
    {
        if (null === static::$instance) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * @return string|null
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @return string|null
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->config = $config ?? [];
        $this->user = $this->config['user'] ?? null;
        $this->password = $this->config['password'] ?? null;
    }

    /**
     * @return array
     */
    public function getRuntimeInformation()
    {
        $result = [
            'repositoryPath' => $this->repositoryPath,
            'username' => $this->user,
            'password' => $this->password
        ];

        foreach ($this->config as $key => $item) {
            if (is_array($item)) {
                $count = count($item);
                $arr = $item;
                if ($count === 0) {// empty array, could still be associative
                    $arr = '[]';
                } else if (isset($item[0])) {// fast check for plain array with numeric keys
                    $arr = '[\'' . implode('\', \'', $item) . '\']';
                }
                $result[$key] = $arr;
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param string $url
     * @return string[]
     */
    public function testRepository($url, $branch)
    {
        if (!preg_match(Helper::GIT_REGEX, $url)) {
            throw new \RuntimeException("Git Repository value does not match the supported format.");
        }

        $branch = $branch ? '"' . $branch . '"' : '';
        return $this->execute("ls-remote \"{$url}\" {$branch}");
    }

    /**
     * @return bool
     */
    public function initializeRepository()
    {
        if (!Helper::isGitInitialized()) {
            $branch = $this->getRemote('branch', null);
            $local_branch = $this->getConfig('branch', $branch);
            $this->execute('init');
            $this->execute('checkout -b ' . $local_branch, true);
        }

        $this->enableSparseCheckout();

        return true;
    }

    /**
     * @param string|null $name
     * @param string|null $email
     * @return bool
     */
    public function setUser($name = null, $email = null)
    {
        $gitConfig = $this->getConfig('git', []) ?? [];
        // Fall back to defaults when the config value is missing OR an empty
        // string — `??` alone leaves a blank name/email in place, which makes
        // git reject the commit with "fatal: empty ident name ... not allowed".
        $name = $name ?: (($gitConfig['name'] ?? '') ?: 'GitSync');
        $email = $email ?: (($gitConfig['email'] ?? '') ?: 'git-sync@trilby.media');
        $privateKey = $this->getGitConfig('private_key', null);

        $this->execute("config user.name \"{$name}\"");
        $this->execute("config user.email \"{$email}\"");

        if ($privateKey) {
            $this->execute('config core.sshCommand "ssh -i ' . $privateKey . ' -F /dev/null"');
        } else {
            $this->execute('config --unset core.sshCommand');
        }

        return true;
    }

    /**
     * @param string|null $name
     * @return bool
     */
    public function hasRemote($name = null)
    {
        $name = $this->getRemote('name', $name);

        // List the configured remotes and check for membership. `git remote`
        // exits 0 even when there are none, so this stays a genuine existence
        // check. The previous approach ran `remote get-url <name>` non-quiet and
        // relied on the resulting error being thrown and caught — which, with
        // logging enabled, wrote a misleading "error: No such remote" line every
        // time a remote simply hadn't been added yet.
        $remotes = array_map('trim', $this->execute('remote', true));

        return in_array($name, $remotes, true);
    }

    /**
     * Top-level trees recorded in HEAD.
     *
     * HEAD rather than the index because that is what `reset --hard` rebuilds the
     * working tree from, and top-level only because that is the granularity
     * `folders:` works at -- listing every tracked file would mean reading the
     * whole page tree.
     *
     * Nothing is tracked before the first commit. That has to be checked separately
     * rather than by looking for empty `ls-tree` output, because `execute()` folds
     * stderr into its return value -- on a repository with no commits `ls-tree`
     * yields "fatal: Not a valid object name HEAD", which would otherwise read as a
     * tracked folder called exactly that. `rev-parse --quiet` prints nothing at all
     * when HEAD is unborn.
     *
     * @return array
     */
    protected function getTrackedFolders()
    {
        if (!array_filter(array_map('trim', $this->execute('rev-parse --quiet --verify HEAD', true)))) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $this->execute('ls-tree -d --name-only HEAD', true))));
    }

    /**
     * The folder list Git Sync narrowed this repository down to on the last save,
     * keyed by first path segment.
     *
     * Kept in the repository's own git config rather than derived from
     * `.git/info/sparse-checkout`, because the patterns now also carry folders Git
     * Sync does not manage -- reading them back would make those look like ours on
     * the next save, which is the bug this exists to prevent. Local to the clone and
     * never pushed.
     *
     * An install upgrading from a release that did not record it has no key yet, so
     * fall back to the sparse patterns once: for those installs the patterns are
     * exactly the managed list, and the value is written back on this save.
     *
     * @return array
     */
    protected function getManagedFolders()
    {
        $recorded = trim(implode('', $this->execute('config --local --get gitsync.folders', true)));

        if ($recorded !== '' && strpos($recorded, 'error:') === false && strpos($recorded, 'fatal:') === false) {
            return $this->firstSegments(explode(',', $recorded));
        }

        $file = rtrim($this->repositoryPath, '/') . '/.git/info/sparse-checkout';
        if (!is_file($file)) {
            return [];
        }

        $patterns = array_filter(array_map('trim', (array) file($file)));

        return $this->firstSegments(array_map(static function ($pattern) {
            return rtrim($pattern, '*');
        }, $patterns));
    }

    /**
     * Record the folder list Git Sync is managing from this save onwards.
     *
     * @param array $folders
     * @return void
     */
    protected function setManagedFolders(array $folders)
    {
        $this->execute('config --local gitsync.folders ' . escapeshellarg(implode(',', $folders)), true);
    }

    /**
     * Reduce folder paths to the set of their first path segments.
     *
     * @param array $folders
     * @return array
     */
    protected function firstSegments(array $folders)
    {
        $segments = [];
        foreach ($folders as $folder) {
            $folder = trim(str_replace('\\', '/', (string) $folder), '/');
            if ($folder !== '') {
                $segments[explode('/', $folder)[0]] = true;
            }
        }

        return $segments;
    }

    /**
     * Stop tracking folders that are no longer in the sync list, leaving them
     * untouched on disk.
     *
     * The repository root is `user/` (see `$repositoryPath`), and sparse-checkout
     * is what narrows the working tree down to the configured folders. A path
     * still recorded in HEAD but no longer matched by the sparse patterns is one
     * git considers "must not exist in the working tree", so the next `pull` or
     * `reset --hard HEAD` deletes it from disk — the folder itself included.
     * Removing `pages` from the sync list therefore wiped `user/pages` off the
     * filesystem and took the site down with it (#257).
     *
     * Dropping those paths from the index first puts them out of git's reach, so
     * no later sync or reset can touch them.
     *
     * Only folders Git Sync itself was tracking are candidates. A repository root
     * is an ordinary git repository and can legitimately hold anything alongside
     * the folders in the sync list -- `docs`, `.github`, editor directories -- and
     * those were never Git Sync's to remove. Comparing HEAD against the sync list
     * alone could not tell one apart from a folder just de-listed, so connecting to
     * an existing repository untracked them and pushed the removal, taking them off
     * the remote and out of every other clone (#262).
     *
     * @param array $folders the folders that should remain tracked
     * @param array $previous the folders Git Sync was tracking before this save,
     *                        keyed by first path segment; empty when unknown
     * @return void
     */
    protected function pruneUnsyncedFolders(array $folders, array $previous)
    {
        // No record of a previous list means Git Sync has never narrowed this
        // repository down, so nothing here was ever ours to untrack.
        if (!$previous) {
            return;
        }

        $tracked = $this->getTrackedFolders();
        if (!$tracked) {
            return;
        }

        // A nested entry such as `pages/blog` keeps the whole `pages` tree in
        // play, so compare on the first path segment.
        $keep = [];
        foreach ($folders as $folder) {
            $folder = trim(str_replace('\\', '/', (string) $folder), '/');
            if ($folder !== '') {
                $keep[explode('/', $folder)[0]] = true;
            }
        }

        // Prune only what the previous list tracked and the new one drops. Note
        // `rm --cached` is deliberately left without `--sparse`: git refuses to touch
        // index entries outside the active sparse patterns, which is a second line of
        // defence against untracking someone else's folders.
        $stale = array_values(array_filter($tracked, static function ($folder) use ($keep, $previous) {
            return isset($previous[$folder]) && !isset($keep[$folder]);
        }));

        if (!$stale) {
            return;
        }

        foreach ($stale as $folder) {
            $this->execute('rm -r --cached --ignore-unmatch ' . escapeshellarg($folder), true);
        }

        // The removal has to be committed: `reset --hard HEAD` rebuilds the index
        // from HEAD, so a merely staged removal would protect nothing. The
        // committer is pinned inline because `setUser()` has not necessarily run
        // yet at this point in the save. Same fallbacks as `setUser()`, empty
        // string included -- git rejects a commit with a blank ident.
        $gitConfig = $this->getConfig('git', []) ?? [];
        $name = ($gitConfig['name'] ?? '') ?: 'GitSync';
        $email = ($gitConfig['email'] ?? '') ?: 'git-sync@trilby.media';
        $message = '(Grav GitSync) Stopped tracking ' . implode(', ', $stale)
            . ' after removal from the sync list (files left on disk)';

        $this->execute(
            '-c ' . escapeshellarg('user.name=' . $name)
            . ' -c ' . escapeshellarg('user.email=' . $email)
            . ' commit -m ' . escapeshellarg($message),
            true
        );
    }

    public function enableSparseCheckout()
    {
        $folders = $this->config['folders'] ?? ['pages'];

        // Must be read before the new list is recorded, and the prune must run
        // before the new patterns are written, while HEAD still reflects what the
        // previous folder list was tracking.
        $previous = $this->getManagedFolders();

        $this->pruneUnsyncedFolders($folders, $previous);

        $this->execute('config core.sparsecheckout true');

        // A tracked folder Git Sync does not manage still has to match a pattern.
        // Sparse checkout reads anything unmatched as "must not exist in the working
        // tree", so leaving it out deleted it from disk on the next pull or reset --
        // the same way de-listing a folder used to (#257), but aimed at folders
        // nobody asked us to handle (#262).
        $unmanaged = array_diff($this->getTrackedFolders(), array_keys($this->firstSegments($folders)));

        $sparse = [];
        foreach (array_merge($folders, $unmanaged) as $folder) {
            $sparse[] = $folder . '/';
            $sparse[] = $folder . '/*';
        }

        $file = File::instance(rtrim($this->repositoryPath, '/') . '/.git/info/sparse-checkout');
        $file->save(implode("\r\n", $sparse));
        $file->free();

        // From here on this is the list we own, and the only one a later save may
        // prune against.
        $this->setManagedFolders($folders);

        $ignore = ['/*'];
        foreach ($folders as $folder) {
            $folder = rtrim($folder,'/');
            $nested = substr_count($folder, '/');

            if ($nested) {
                $subfolders = explode('/', $folder);
                $nested_tracking = '';
                foreach ($subfolders as $index => $subfolder) {
                    $last = $index === (count($subfolders) - 1);
                    $nested_tracking .= $subfolder . '/';
                    if (!in_array('!/' . $nested_tracking, $ignore, true)) {
                        $ignore[] = rtrim($nested_tracking . (!$last ? '*' : ''), '/');
                        $ignore[] = rtrim('!/' . $nested_tracking, '/');
                    }
                }
            } else {
                $ignore[] = '!/' . $folder;
            }
        }

        $ignoreEntries = explode("\n", $this->getGitConfig('ignore', ''));
        $ignore = array_merge($ignore, $ignoreEntries);

        $file = File::instance(rtrim($this->repositoryPath, '/') . '/.gitignore');
        $file->save(implode("\r\n", $ignore));
        $file->free();
    }

    /**
     * @param string|null $alias
     * @param string|null $url
     * @param bool $authenticated
     * @return string[]
     */
    public function addRemote($alias = null, $url = null, $authenticated = false)
    {
        $alias = $this->getRemote('name', $alias);
        $url = $this->getConfig('repository', $url);

        if ($authenticated) {
            $user = $this->user ?? '';
            $password = $this->password ? Helper::decrypt($this->password) : '';
            $url = Helper::prepareRepository($user, $password, $url);
        }

        $command = $this->hasRemote($alias) ? 'set-url' : 'add';

        return $this->execute("remote {$command} {$alias} \"{$url}\"");
    }

    /**
     * @return string[]
     */
    public function add()
    {
        /** @var string $version */
        $version = Helper::isGitInstalled(true);
        $add = 'add';

        // With the introduction of customizable paths,
        // it appears that the add command should always
        // add everything that is not committed to ensure
        // there are no orphan changes left behind

        /*
        $folders = $this->config['folders'] ?? ['pages'];
        $paths = [];
        foreach ($folders as $folder) {
            $paths[] = $folder;
        }
        */

        $paths = ['.'];

        if (version_compare($version, '2.0', '<')) {
            $add .= ' --all';
        }

        return $this->execute($add . ' ' . implode(' ', $paths));
    }

    /**
     * Remember the page behind the current save / delete / media change so the
     * commit message placeholders can be filled from the object itself.
     *
     * Admin-classic submits a page save as a form POST (`data[header][title]`,
     * `data[route]`), but admin-next saves through the API plugin with a JSON
     * body in a completely different shape, so scraping the request alone
     * yields "NO TITLE FOUND" / "NO ROUTE FOUND" (#254). Every save/delete/media
     * event carries the page object regardless of which admin fired it.
     *
     * @param PageInterface|object|null $page
     */
    public function setPage($page = null): void
    {
        $this->page = $page instanceof PageInterface ? $page : null;
    }

    /**
     * Title and route of the page being committed, if it can be determined.
     *
     * Prefers the page object captured from the save event, then falls back to
     * the request body — admin-classic's `data.*` shape first, then the flat
     * keys the API plugin's page endpoints use.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function getPageContext(): array
    {
        $title = null;
        $route = null;

        if ($this->page !== null) {
            $title = $this->page->title();
            $route = $this->page->rawRoute() ?: $this->page->route();
        }

        if (!$title || !$route) {
            $uri = $this->grav['uri'];
            $title = $title ?: ($uri->post('data.header.title') ?: $uri->post('header.title') ?: $uri->post('title'));
            $route = $route ?: ($uri->post('data.route') ?: $uri->post('route'));
        }

        return [is_string($title) ? $title : null, is_string($route) ? $route : null];
    }

    /**
     * The Grav user behind the current change, for the `gravuser` / `gravfull`
     * committer options.
     *
     * Under admin-next the request is authenticated by the API plugin (API key,
     * JWT or session passthrough) and the resulting account hangs off the admin
     * proxy — it is not necessarily on the session, and `$grav['user']` may
     * still be the guest. Try each source in turn and take the first one that
     * actually names a user.
     *
     * @return UserInterface|null
     */
    protected function getGravUser()
    {
        $candidates = [];

        $admin = $this->grav['admin'] ?? null;
        if ($admin !== null && isset($admin->user)) {
            $candidates[] = $admin->user;
        }

        $session = isset($this->grav['session']) ? $this->grav['session'] : null;
        if ($session !== null && isset($session->user)) {
            $candidates[] = $session->user;
        }

        $candidates[] = $this->grav['user'] ?? null;

        foreach ($candidates as $candidate) {
            if ($candidate instanceof UserInterface && ($candidate->username ?? '') !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param string $message
     * @return string[]
     */
    public function commit($message = '(Grav GitSync) Automatic Commit')
    {
        $authorType = $this->getGitConfig('author', 'gituser');
        if (defined('GRAV_CLI') && in_array($authorType, ['gravuser', 'gravfull'])) {
            $authorType = 'gituser';
        }

        // get message from config, it any, or stick to the default one
        $config = $this->getConfig('git', null);
        $message = $config['message'] ?? $message;

        [$page_title, $page_route] = $this->getPageContext();

        $pageTitle = $page_title ?: 'NO TITLE FOUND';
        $pageRoute = $page_route ?: 'NO ROUTE FOUND';

        // include page title and route in the message, if placeholders exist
        $message = str_replace('{{pageTitle}}', $pageTitle, $message);
        /** @var string $message */
        $message = str_replace('{{pageRoute}}', $pageRoute, $message);

        $gitConfig = $this->getConfig('git', []) ?? [];
        switch ($authorType) {
            case 'gitsync':
                $user = $gitConfig['name'] ?? 'GitSync';
                $email = $gitConfig['email'] ?? 'git-sync@trilby.media';
                break;
            case 'gravuser':
                $gravUser = $this->getGravUser();
                $user = $gravUser->username ?? 'GitSync';
                $email = $gravUser->email ?? 'git-sync@trilby.media';
                break;
            case 'gravfull':
                $gravUser = $this->getGravUser();
                $user = $gravUser->fullname ?? 'GitSync';
                $email = $gravUser->email ?? 'git-sync@trilby.media';
                break;
            case 'gituser':
            default:
                $user = $this->user ?? 'GitSync';
                $email = $gitConfig['email'] ?? 'git-sync@trilby.media';
                break;
        }

        // Guard against empty values from any source (e.g. a Grav user with no
        // full name set, or a blank committer field) — an empty author name
        // triggers git's "fatal: empty ident name ... not allowed".
        $user = $user ?: 'GitSync';
        $email = $email ?: 'git-sync@trilby.media';

        $author = $user . ' <' . $email . '>';
        $author = '--author="' . $author . '"';
        $message .= ' from ' . $user;
        $this->add();

        return $this->execute('commit ' . $author . ' -m ' . escapeshellarg($message));
    }

    /**
     * @param string|null $name
     * @param string|null $branch
     * @return string[]
     */
    public function fetch($name = null, $branch = null)
    {
        $name = $this->getRemote('name', $name);
        $branch = $this->getRemote('branch', $branch);

        return $this->execute("fetch {$name} {$branch}");
    }

    /**
     * @param string|null $name
     * @param string|null $branch
     * @return string[]
     */
    public function pull($name = null, $branch = null)
    {
        $name = $this->getRemote('name', $name);
        $branch = $this->getRemote('branch', $branch);
        /** @var string $version */
        $version = Helper::isGitInstalled(true);
        $unrelated_histories = '--allow-unrelated-histories';

        // --allow-unrelated-histories starts at 2.9.0
        if (version_compare($version, '2.9.0', '<')) {
            $unrelated_histories = '';
        }

        return $this->execute("pull {$unrelated_histories} --ff -X theirs {$name} {$branch}");
    }

    /**
     * @param string|null $name
     * @param string|null $branch
     * @return string[]
     */
    public function push($name = null, $branch = null)
    {
        $name = $this->getRemote('name', $name);
        $branch = $this->getRemote('branch', $branch);
        $local_branch = $this->getConfig('branch', null);

        return $this->execute("push {$name} {$local_branch}:{$branch}");
    }

    /**
     * @param string|null $name
     * @param string|null $branch
     * @return bool
     */
    public function sync($name = null, $branch = null)
    {
        $name = $this->getRemote('name', $name);
        $branch = $this->getRemote('branch', $branch);
        $this->addRemote(null, null, true);

        $this->fetch($name, $branch);
        $this->pull($name, $branch);
        if ($this->grav['config']->get('plugins.git-sync.sync.direction', 'both') == 'both') {
            $this->push($name, $branch);
        }

        $this->addRemote();

        return true;
    }

    /**
     * @return string[]
     */
    public function reset()
    {
        return $this->execute('reset --hard HEAD');
    }

    /**
     * @return bool
     */
    public function isWorkingCopyClean()
    {
        $message = 'nothing to commit';
        $output = $this->execute('status');

        return strpos($output[count($output) - 1], $message) === 0;
    }

    /**
     * @return bool
     */
    public function hasChangesToCommit()
    {
        $folders = $this->config['folders'] ?? ['pages'];
        $paths = [];

        foreach ($folders as $folder) {
            $folder = explode('/', $folder);
            $paths[] = array_shift($folder);
        }

        $message = 'nothing to commit';
        $output = $this->execute('status ' . implode(' ', $paths));

        return strpos($output[count($output) - 1], $message) !== 0;
    }

    /**
     * @param string $command
     * @param bool $quiet
     * @return string[]
     */
    public function execute($command, $quiet = false)
    {
        try {
            $bin = Helper::getGitBinary($this->getGitConfig('bin', 'git'));
            /** @var string $version */
            $version = Helper::isGitInstalled(true);

            // -C <path> supported from 1.8.5 and above
            if (version_compare($version, '1.8.5', '>=')) {
                $command = $bin . ' -C ' . escapeshellarg($this->repositoryPath) . ' ' . $command;
            } else {
                $command = 'cd ' . $this->repositoryPath . ' && ' . $bin . ' ' . $command;
            }

            $command .= ' 2>&1';

            if (DIRECTORY_SEPARATOR === '/') {
                $command = 'LC_ALL=C ' . $command;
            }

            if ($this->getConfig('logging', false)) {
                $log_command = Helper::preventReadablePassword($command, $this->password ?? '');
                $this->grav['log']->notice('gitsync[command]: ' . $log_command);

                exec($command, $output, $returnValue);

                $log_output = Helper::preventReadablePassword(implode("\n", $output), $this->password ?? '');
                $this->grav['log']->notice('gitsync[output]: ' . $log_output);
            } else {
                exec($command, $output, $returnValue);
            }

            if ($returnValue !== 0 && $returnValue !== 5 && !$quiet) {
                throw new \RuntimeException(implode("\r\n", $output));
            }

            return $output;
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $message = Helper::preventReadablePassword($message, $this->password ?? '');

            // handle scary messages
            if (Utils::contains($message, 'remote: error: cannot lock ref')) {
                $message = 'GitSync: An error occurred while trying to synchronize. This could mean GitSync is already running. Please try again.';
            }

            throw new \RuntimeException($message);
        }

        return 0;
    }

    /**
     * @param string $type
     * @param mixed $value
     * @return mixed
     */
    public function getGitConfig($type, $value)
    {
        return $this->config['git'][$type] ?? $value;
    }

    /**
     * @param string $type
     * @param mixed $value
     * @return mixed
     */
    public function getRemote($type, $value)
    {
        return $value ?: ($this->config['remote'][$type] ?? $value);
    }

    /**
     * @param string $type
     * @param mixed $value
     * @return mixed
     */
    public function getConfig($type, $value)
    {
        return $value ?: ($this->config[$type] ?? $value);
    }
}
