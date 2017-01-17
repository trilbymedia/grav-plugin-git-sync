# v1.0.0-rc.2
##  01/16/2017

1. [](#new)
    * Allow to change the path for the `git` binary (#1)
    * Added CLI for synchronizing `bin/plugin git-sync sync` (#2)
    * More security: Git password will now get encrypted and won't load in admin
1. [](#improved)
    * Wizard: Improved Bitbucket explanation about stripping out `user@` from the copied HTTPS url (#3)
1. [](#bugfix)
    * Fixed potential issue when retrieving the currently installed git version
    * Fixed issue that would not properly hide the password from error messages if the password contained special chars
    * Fixed issue preventing the plugin to properly get setup the very first time and causing 401 error (#4)
    * Workaround for error thrown when removing the plugin

# v1.0.0-rc.1
##  12/19/2016

1. [](#new)
    * Initial Release
