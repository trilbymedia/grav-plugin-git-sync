<?php

namespace Grav\Plugin\GitSync;

use SebastianBergmann\Git\RuntimeException;

class Helper {

    /**
     * Checks if the user/ folder is already initialized
     *
     * @return bool
     */
    public static function isGitInitialized() {
        return file_exists('user/.git');
    }

    public static function testRepository($user, $password, $repository) {
        $git = new GitSync();
        $repository = str_replace('://', "://${user}:${password}@", $repository);

        try {
            return $git->testRepository($repository);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }
}
