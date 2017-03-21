# v1.0.3
## 02/21/2017

1. [](#bugfix)
    * Fixed issue with new 'author' option that could trigger errors when settings were not saved. (#23)
    * Fixed the 'More Details' button triggering the Modal to close instead of just expanding the details
    
# v1.0.2
## 02/18/2017

1. [](#new)
    * It is now possible to change the committer name. You can choose between Git User, GitSync Committer Name, Grav User Name, Grav User Fullname (#14).
2. [](#improved)
    * Added more documentation and description about the support of 2FA and Access Tokens (#16, #19, thanks @OleVik)
    * Added 4th Generic Git choice in the wizard for self-hosted and custom git services (Gogs/Gitea) (#7 - #22 - thanks @erlepereira)
1. [](#bugfix)
    * Fixed issue preventing the custom Git Binary Path from getting used (#15)
    * Fixed issue with Webhook auto-generated URL where it would display double slashes in case of root domain (#15)
    * Fixed issue with the modal not properly restoring the tutorial steps of the active selected service
    
# v1.0.1
## 01/29/2017

1. [](#bugfix)
    * Changed default GitSync email for commits
    
# v1.0.0
## 01/25/2017

1. [](#new)
    * Released plugin to stable GPM channel

# v1.0.0-rc.3
## 01/19/2017

1. [](#new)
    * Added logger setting to log Git command executions
1. [](#improved)
    * Improved Windows compatibility

# v1.0.0-rc.2
## 01/16/2017

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
