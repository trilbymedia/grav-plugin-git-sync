<?php

namespace Grav\Plugin\GitSync;

use Defuse\Crypto\Crypto;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Utils;
use SebastianBergmann\Git\RuntimeException;

class Helper
{
    /** @var string */
    private static $hash = '594ef69d-6c29-45f7-893a-f1b4342687d3';

    /** @var string */
    const GIT_REGEX = '/(?:git|ssh|https?|git@[-\w.]+):(\/\/)?(.*?)(\.git)(\/?|\#[-\d\w._]+?)$/';

    /**
     * Checks if git-sync is properly configured with a repository URL
     *
     * @return bool
     */
    public static function isGitSyncConfigured()
    {
        $config = Grav::instance()['config']->get('plugins.git-sync');
        $repository = $config['repository'] ?? null;
        return !empty($repository);
    }

    /**
     * Checks if git-sync is ready to use (installed, configured, and initialized)
     *
     * @return bool
     */
    public static function isGitSyncReady()
    {
        return static::isGitInstalled() && static::isGitSyncConfigured() && static::isGitInitialized();
    }

    /**
     * Checks if the user/ folder is already initialized
     *
     * @return bool
     */
    public static function isGitInitialized()
    {
        /** @var Config $grav */
        $config = Grav::instance()['config']->get('plugins.git-sync');
        $repositoryPath = isset($config['local_repository']) && $config['local_repository'] ? $config['local_repository'] : USER_DIR;
        return file_exists(rtrim($repositoryPath, '/') . '/.git');
    }

    /**
     * @param bool $version
     * @return bool|string
     */
    public static function isGitInstalled($version = false)
    {
        $bin = Helper::getGitBinary();

        exec($bin . ' --version', $output, $returnValue);

        $installed = $returnValue === 0;

        if ($version && $output) {
            $output = explode(' ', array_shift($output));
            $versions = array_filter($output, static function($item) {
                return version_compare($item, '0.0.1', '>=');
            });

            $installed = array_shift($versions);
        }

        return $installed;
    }

    /**
     * @param bool $override
     * @return string
     */
    public static function getGitBinary($override = false)
    {
        /** @var Config $grav */
        $config = Grav::instance()['config'];

        return $override ?: $config->get('plugins.git-sync.git.bin', 'git');
    }

    /**
     * @param string $user
     * @param string $password
     * @param string $repository
     * @return string
     */
    public static function prepareRepository($user, $password, $repository)
    {
        $user = $user ? urlencode($user) . ':' : '';
        $password = urlencode($password);

        if (Utils::startsWith($repository, 'ssh://')) {
            return $repository;
        }

        return str_replace('://', "://{$user}{$password}@", $repository);
    }

    /**
     * Whether a repository URL carries a password in its user info.
     *
     * @param string $url
     * @return bool
     */
    public static function hasEmbeddedPassword($url)
    {
        $password = parse_url((string) $url, PHP_URL_PASS);

        return is_string($password) && $password !== '';
    }

    /**
     * @param string $user
     * @param string $password
     * @param string $repository
     * @return string[]
     */
    public static function testRepository($user, $password, $repository, $branch)
    {
        $git = new GitSync();
        $repository = self::prepareRepository($user, $password, $repository);

        try {
            return $git->testRepository($repository, $branch);
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }
    }

    /**
     * @param string $password
     * @return string
     * @throws \Defuse\Crypto\Exception\EnvironmentIsBrokenException
     */
    public static function encrypt($password)
    {
        return 'gitsync-' . Crypto::encryptWithPassword($password, self::$hash);
    }

    /**
     * @param string $enc_password
     * @return string
     */
    public static function decrypt($enc_password)
    {
        if (strpos($enc_password, 'gitsync-') === 0) {
            $enc_password = substr($enc_password, 8);

            return Crypto::decryptWithPassword($enc_password, self::$hash);
        }

        return $enc_password;
    }

    /**
     * @return bool
     */
    public static function synchronize()
    {
        if (!self::isGitInstalled() || !self::isGitInitialized()) {
            return true;
        }

        $git = new GitSync();

        if ($git->hasChangesToCommit()) {
            $git->commit();
        }

        // synchronize with remote
        $git->sync();

        return true;
    }

    /**
     * @param string $str
     * @param string $password
     * @return string
     */
    public static function preventReadablePassword($str, $password)
    {
        $encoded = urlencode(self::decrypt($password));
        if ($encoded !== '') {
            $str = str_replace($encoded, '{password}', $str);
        }

        // Mask any password sitting in a URL as well, not only the stored one.
        // The connection test runs with credentials that have not been saved
        // yet, so with logging on they reached the log in cleartext.
        return preg_replace('#(://[^/\s:@"\']*):[^/\s@"\']+@#', '$1:{password}@', $str) ?? $str;
    }
}
