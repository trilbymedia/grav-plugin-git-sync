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
     * Checks if the user/ folder is already initialized
     *
     * @return bool
     */
    public static function isGitInitialized()
    {
        return file_exists(rtrim(USER_DIR, '/') . '/.git');
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

        return str_replace('://', "://${user}${password}@", $repository);
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

        return str_replace($encoded, '{password}', $str);
    }
}
