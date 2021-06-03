<?php
namespace Grav\Plugin\GitSync;

use Grav\Common\Grav;
use Grav\Common\Plugin;
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

    /** @var string|null */
    private $user;
    /** @var string|null */
    private $password;

    public function __construct()
    {
        $this->grav = Grav::instance();
        $this->config = $this->grav['config']->get('plugins.git-sync');
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
        $this->config = $config;
        $this->user = $this->config['user'];
        $this->password = $this->config['password'];
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
        $name = $this->getConfig('git', $name)['name'];
        $email = $this->getConfig('git', $email)['email'];
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

        try {
            /** @var string $version */
            $version = Helper::isGitInstalled(true);
            // remote get-url 'name' supported from 2.7.0 and above
            if (version_compare($version, '2.7.0', '>=')) {
                $command = "remote get-url \"{$name}\"";
            } else {
                $command = "config --get remote.{$name}.url";
            }

            $this->execute($command);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    public function enableSparseCheckout()
    {
        $folders = $this->config['folders'];
        $this->execute('config core.sparsecheckout true');

        $sparse = [];
        foreach ($folders as $folder) {
            $sparse[] = $folder . '/';
            $sparse[] = $folder . '/*';
        }

        $file = File::instance(rtrim($this->repositoryPath, '/') . '/.git/info/sparse-checkout');
        $file->save(implode("\r\n", $sparse));
        $file->free();

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
        $folders = $this->config['folders'];
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

        // get Page Title and Route from Post
        $uri = $this->grav['uri'];
        $page_title = $uri->post('data.header.title');
        $page_route = $uri->post('data.route');

        $pageTitle = $page_title ?: 'NO TITLE FOUND';
        $pageRoute = $page_route ?: 'NO ROUTE FOUND';

        // include page title and route in the message, if placeholders exist
        $message = str_replace('{{pageTitle}}', $pageTitle, $message);
        /** @var string $message */
        $message = str_replace('{{pageRoute}}', $pageRoute, $message);

        switch ($authorType) {
            case 'gitsync':
                $user = $this->getConfig('git', null)['name'];
                $email = $this->getConfig('git', null)['email'];
                break;
            case 'gravuser':
                $user = $this->grav['session']->user->username;
                $email = $this->grav['session']->user->email;
                break;
            case 'gravfull':
                $user = $this->grav['session']->user->fullname;
                $email = $this->grav['session']->user->email;
                break;
            case 'gituser':
            default:
                $user = $this->user;
                $email = $this->getConfig('git', null)['email'];
                break;
        }

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

        return $this->execute("pull {$unrelated_histories} -X theirs {$name} {$branch}");
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
        $this->push($name, $branch);

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
        $folders = $this->config['folders'];
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
