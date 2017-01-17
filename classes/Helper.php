<?php

namespace Grav\Plugin\GitSync;

use Defuse\Crypto\Crypto;
use Grav\Common\Grav;
use SebastianBergmann\Git\RuntimeException;

class Helper {

    private static $hash = '594ef69d-6c29-45f7-893a-f1b4342687d3';

    /**
     * Checks if the user/ folder is already initialized
     *
     * @return bool
     */
    public static function isGitInitialized()
    {
        return file_exists(rtrim(USER_DIR, '/') . '/.git');
    }

    public static function isGitInstalled($version = false)
    {
        $bin = Helper::getGitBinary();

        exec($bin . ' --version', $output, $returnValue);

        $installed = $returnValue !== 0 ? false : true;

        if ($version && $output) {
            $output = explode(' ', array_shift($output));
            $installed = array_filter($output, function($item) {
                return version_compare($item, '0.0.1', '>=');
            });
            $installed = array_shift($installed);
        }

        return $installed;
    }

    public static function getGitBinary($override = false)
    {
        $grav = Grav::instance()['config'];

        return $override ?: $grav->get('plugins.git-sync.git.bin', 'git');
    }

    public static function prepareRepository($user, $password, $repository)
    {
        $password = urlencode($password);
        return str_replace('://', "://${user}:${password}@", $repository);
    }

    public static function testRepository($user, $password, $repository) {
        $git = new GitSync();
        $repository = self::prepareRepository($user, $password, $repository);

        try {
            return $git->testRepository($repository);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public static function encrypt($password)
    {
        return 'gitsync-' . Crypto::encryptWithPassword($password, self::$hash);
    }

    public static function decrypt($enc_password)
    {
        if (substr($enc_password, 0, 8) === 'gitsync-') {
            $enc_password = substr($enc_password, 8);
            return Crypto::decryptWithPassword($enc_password, self::$hash);
        } else {
            return $enc_password;
        }
    }
}
