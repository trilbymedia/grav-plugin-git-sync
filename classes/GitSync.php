<?php
namespace Grav\Plugin\GitSync;

use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use RocketTheme\Toolbox\File\File;
use SebastianBergmann\Git\Git;

class GitSync extends Git
{
    private $user;
    private $password;
    protected $grav;
    protected $config;
    protected $repositoryPath;
    static public $instance = null;

    public function __construct(Plugin $plugin = null)
    {
        parent::__construct(USER_DIR);
        static::$instance = $this;
        $this->grav = Grav::instance();
        $this->config = $this->grav['config']->get('plugins.git-sync');
        $this->repositoryPath = USER_DIR;

        $this->user = isset($this->config['user']) ? $this->config['user'] : null;
        $this->password = isset($this->config['password']) ? $this->config['password'] : null;

        unset($this->config['user']);
        unset($this->config['password']);
    }

    static public function instance()
    {
        return static::$instance = is_null(static::$instance) ? new static : static::$instance;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function setConfig($obj)
    {
        $this->config = $obj;
        $this->user = $this->config['user'];
        $this->password = $this->config['password'];
    }

    public function testRepository($url)
    {
        return $this->execute("ls-remote \"${url}\"");
    }

    public function initializeRepository($force = false)
    {
        if ($force || !Helper::isGitInitialized()) {
            $this->execute('init');
            return $this->enableSparseCheckout();
        }

        return true;
    }

    public function setUser($name = null, $email = null)
    {
        $name = $this->getConfig('git', $name)['name'];
        $email = $this->getConfig('git', $email)['email'];

        $this->execute("config user.name \"{$name}\"");
        $this->execute("config user.email \"{$email}\"");

        return true;
    }

    public function hasRemote($name = null)
    {
        $name = $this->getRemote('name', $name);

        try {
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
        $this->execute("config core.sparsecheckout true");

        $sparse = [];
        foreach ($folders as $folder) {
            $sparse[] = $folder . '/';
            $sparse[] = $folder . '/*';
        }

        $file = File::instance(rtrim(USER_DIR, '/') . '/.git/info/sparse-checkout');
        $file->save(implode("\r\n", $sparse));
        $file->free();

        $ignore = ['/*'];
        foreach ($folders as $folder) {
            $ignore[] = '!/' . $folder;
        }

        $file = File::instance(rtrim(USER_DIR, '/') . '/.gitignore');
        $file->save(implode("\r\n", $ignore));
        $file->free();
    }

    public function addRemote($alias = null, $url = null, $authenticated = false)
    {
        $alias = $this->getRemote('name', $alias);
        $url = $this->getConfig('repository', $url);

        if ($authenticated) {
            $user = $this->user ?: $this->config->get('user');
            $password = Helper::decrypt($this->password ?: $this->config->get('password'));
            $url = Helper::prepareRepository($user, $password, $url);
        }

        $command = $this->hasRemote($alias) ? 'set-url' : 'add';

        return $this->execute("remote ${command} ${alias} \"${url}\"");
    }

    public function add()
    {
        $version = Helper::isGitInstalled(true);
        $folders = $this->config['folders'];
        $paths = [];
        $add = 'add';

        foreach ($folders as $folder) {
            $paths[] = $folder;
        }

        if (version_compare($version, '1.8.1.4', '<')) {
            $add .= ' --all';
        }

        return $this->execute($add . ' ' . implode(' ', $paths));
    }

    public function commit($message = '(Grav GitSync) Automatic Commit')
    {
        $authorType = $this->getGitConfig('author', 'gituser');
        if (defined('GRAV_CLI') && in_array($authorType, ['gravuser', 'gravfull'])) {
            $authorType = 'gituser';
        }

        switch ($authorType) {
            case 'gitsync':
                $user = $this->getConfig('git', null)['name'];
                break;
            case 'gravuser':
                $user = $this->grav['session']->user->username;
                break;
            case 'gravfull':
                $user = $this->grav['session']->user->fullname;
                break;
            case 'gituser':
            default:
                $user = $this->user;
                break;
        }

        $author = $user . ' <' . $this->getConfig('git', null)['email'] . '>';
        $author = '--author="' . $author . '"';
        $message .= ' from ' . $user;
        $this->add();
        return $this->execute("commit " . $author . " -m " . escapeshellarg($message));
    }

    public function fetch($name = null, $branch = null)
    {
        $name = $this->getRemote('name', $name);
        $branch = $this->getRemote('branch', $branch);

        return $this->execute("fetch {$name} {$branch}");
    }

    public function pull($name = null, $branch = null)
    {
        $name = $this->getRemote('name', $name);
        $branch = $this->getRemote('branch', $branch);
        $version = $version = Helper::isGitInstalled(true);
        $unrelated_histories = '--allow-unrelated-histories';

        // --allow-unrelated-histories starts at 2.9.0
        if (version_compare($version, '2.9.0', '<')) {
            $unrelated_histories = '';
        }

        return $this->execute("pull {$unrelated_histories} -X theirs {$name} {$branch}");
    }

    public function push($name = null, $branch = null)
    {
        $name = $this->getRemote('name', $name);
        $branch = $this->getRemote('branch', $branch);

        return $this->execute("push {$name} {$branch}");
    }

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

    public function reset()
    {
        return $this->execute("reset --hard HEAD");
    }

    public function isWorkingCopyClean()
    {
        $message = 'nothing to commit';
        $output = $this->execute('status');

        return (substr($output[count($output)-1], 0, strlen($message)) === $message);
    }

    public function execute($command)
    {
        try {
            $bin = Helper::getGitBinary($this->getGitConfig('bin', 'git'));
            $version = Helper::isGitInstalled(true);

            // -C <path> supported from 1.8.5 and above
            if (version_compare($version, '1.8.5', '>=')) {
                $command = $bin . ' -C ' . escapeshellarg($this->repositoryPath) . ' ' . $command;
            } else {
                $command = 'cd ' . $this->repositoryPath . ' && ' . $bin . ' ' . $command;
            }

            $command .= ' 2>&1';

            if (DIRECTORY_SEPARATOR == '/') {
                $command = 'LC_ALL=en_US.UTF-8 ' . $command;
            }

            if ($this->getConfig('logging', false)) {
                $log_command = str_replace(urlencode(Helper::decrypt($this->password)), '{password}', $command);
                $this->grav['log']->notice('gitsync[command]: ' . $log_command);

                exec($command, $output, $returnValue);

                $log_output = str_replace(urlencode(Helper::decrypt($this->password)), '{password}', implode("\n", $output));
                $this->grav['log']->notice('gitsync[output]: ' . $log_output);
            } else {
                exec($command, $output, $returnValue);
            }

            if ($returnValue !== 0) {
                throw new \RuntimeException(implode("\r\n", $output));
            }

            return $output;
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $message = str_replace(urlencode(Helper::decrypt($this->password)), '{password}', $message);

            // handle scary messages
            if (Utils::contains($message, "remote: error: cannot lock ref")) {
                $message = 'GitSync: An error occurred while trying to synchronize. This could mean GitSync is already running. Please try again.';
            }

            throw new \RuntimeException($message);
        }
    }

    public function getGitConfig($type, $value)
    {
        return isset($this->config['git']) && isset($this->config['git'][$type]) ? $this->config['git'][$type] : $value;
    }

    public function getRemote($type, $value)
    {
        return !$value && isset($this->config['remote']) ? $this->config['remote'][$type] : $value;
    }

    public function getConfig($type, $value)
    {
        return !$value && isset($this->config[$type]) ? $this->config[$type] : $value;
    }
}
