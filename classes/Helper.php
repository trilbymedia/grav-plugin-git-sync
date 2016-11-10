<?php

namespace Grav\Plugin\GitSync;

class Helper {

    /**
     * Checks if the user/ folder is already initialized
     *
     * @return bool
     */
    public static function isGitInitialized() {
        return file_exists('user/.git');
    }
}
