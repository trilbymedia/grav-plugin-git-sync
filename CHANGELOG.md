# v2.3.2
## 06/03/2021

1. [](#bugfix)
   * Better validation for Git Repository value on both Wizard and Backend. 
   * Prevent malicious commands from being executed in Wizard when "Verifying Authentication, Connection and Branch".

# v2.3.1
## 04/30/2021

1. [](#bugfix)
   * Fixed regression where `testRepository` would erroneously pass with invalid credentials [#200](https://github.com/trilbymedia/grav-plugin-git-sync/issues/200)
   * Fixed Exception thrown with `bin/plugin git-sync status` command, preventing `sync` [#200](https://github.com/trilbymedia/grav-plugin-git-sync/issues/200)

# v2.3.0
## 04/27/2021

1. [](#new)
   * Added new Advanced Git Ignore field where it is possible to specify custom git ignore entries to play along with GitSync [#197](https://github.com/trilbymedia/grav-plugin-git-sync/issues/197) [#117](https://github.com/trilbymedia/grav-plugin-git-sync/issues/117) 
   * Support `ssh://` protocol and SSH Key authentication ([read more](https://github.com/trilbymedia/grav-plugin-git-sync#ssh--enterprise)) [#110](https://github.com/trilbymedia/grav-plugin-git-sync/issues/110)
1. [](#improved)
   * Updated PHP Encryption dependency
1. [](#bugfix)
   * Fixed issue with Flex Objects, preventing GitSync's settings to get refreshed `onAdminSave` when "Sync on Page Save" disabled
   * Return raw URL for repositories setup with `ssh://` protocol, instead of injecting the password like `git://` and `http://` protocols do [#104](https://github.com/trilbymedia/grav-plugin-git-sync/issues/104)

# v2.2.0
## 04/17/2021

1. [](#improved)
   * Better support for branches other than `master`. This includes the transition to `main` from GitHub and the groundwork to support other big providers making the change as announced soon. GitSync is now capable of preset the branch based on the provider selected. You are now also able to specify any custom branch and when testing the repository connection it will also ensure the branch exists and provide feedback if not. 
1. [](#bugfix)
   * Changing remote branch is now going to properly reference it instead of remaining stuck to `master` [#192](https://github.com/trilbymedia/grav-plugin-git-sync/issues/192), [#183](https://github.com/trilbymedia/grav-plugin-git-sync/issues/183)
   * Fixed issue where the Folders to synchronize from the Wizard wouldn't get properly saved [#178](https://github.com/trilbymedia/grav-plugin-git-sync/issues/178)

# v2.1.1
## 07/17/2020

1. [](#new)
    * Added `No User` option to allow disabling the username requirement. This is useful for when you have a token and the user is not required. (#166, thanks GwynethLlewelyn)
    * Added `passwd` command for programmatically change user/password (use: `bin/plugin git-sync passwd`) (#146)
    * Fixed regression wrongly returning the installed Git version and causing all sort of problems, including unrelated histories not kicking off (#61, #168, #171, #173)
    * Fixed potential issue where the new feature `no_user` my throw an error
    * Fixed issue with autoload
1. [](#bugfix)
    * Fixed classes not being loaded in `cli` commands due to Grav changes (#167)
    * Updated dependencies / recompiled JS for production
1. [](#improved)
    * Bumped modules versions

# v2.1.0
## 03/13/2020

1. [](#new)
    * Requires Grav v1.6.0
    * Pass phpstan level 2 tests
1. [](#improved)
    * Code cleanup
    * Added support for Gitea / Gogs webhook secret (#149, thanks @Aisbergg)

# v2.0.5
## 05/06/2019

1. [](#bugfix)
    * Fixed validation error with commalist in Folders to Sync field (#141)

# v2.0.4
## 04/22/2019

1. [](#improved)
    * urlencode username to allow for special characters (#139)

# v2.0.3
## 03/07/2019

1. [](#bugifx)
    * Properly fallback to config message if not there yet (#134)

# v2.0.2
## 02/21/2019

1. [](#improved)
    * Fixed InitCommand spelling (#132, thanks @alex-mohemian)
1. [](#bugfix)
    * Fixed PHP 5.6 incompatibility introduced by latest release.

# v2.0.1
## 02/19/2019

1. [](#new)
    * Added new `init` CLI command (`bin/plugin git-sync init`) (#128, thanks @LeonRyan and @alex-mohemian) 
1. [](#improved)
    * Allow setting a personalised commit message (#123, thanks @kyed)
    * Added better directions for Azure + IIS users for the Git Binary
1. [](#bugfix)
    * Fixed `LC_ALL` to use `C` instead of en_US.UTF-8`, to be more flexible (#124, #125, thanks @lambopedia)
    
# v2.0.0
## 10/15/2018

1. [](#new)
    * Added support for new awesome Grav 1.6 Scheduler
    * Added logic to display custom nested folders in wizard
    * Other than `pages`, it is now possible to enable `config`, `data`, `plugins` and `themes` for synchronization. You can also add any custom folder you have in your `user` (#4, #21, #34, #58, #63, #83)
    * Allow users with `admin.pages` permissions to synchronize through quick tray (#79, thanks @apfrod)
    * When using Grav as committer, the user email will be now used for the commit (#81, thanks @apfrod)
    * Added support for Webhook Secret (Bitbucket does not yet support them) (#72, #73, thanks @pathmissing)
    * Added options to turn automatic synchronization on/off with page saves, delete and media changes (#105, thanks @AmauryCarrade)
1. [](#improved)
    * Fixed alignment of the git icon in the Wizard (#115)
    * Prevent Wizard modal to get canceled when clicking on the overlay background (#115)
    * Quick tray icon is now smarter. If GitSync has not been initialized yet, it will take you straight to wizard, otherwise it would perform a synchronization (#115)
    * Rearranged blueprint order (thanks @paulhibbitts)
    * GitLab: Updated wizard instructions to be inline with the new GitLab UI (#90)
    * Tweaked alignment of links in the wizard (#57)
    * Properly support local branches that aren't `master` (#56)
    * Allow to specify custom local_repository (default, `USER_DIR`) (#95, thanks @Hydraner, also #54, #33, #25)
    * Webhook URL is now more robust and secure, by default it is generated with a random value
    * Git icon from Admin has been replaced to use the `git` text icon instead of the logo
    * Prevent next step if Step 1 and Step 2 are not filled in (#92)
    * Added notice in Step 2 explaning what GitSync expect from the repository structure (#92)
1. [](#bugfix)
    * Fixed issue where on first initialization the checkout process would error out
    * Fixed issue with Pages save. 
    * Fixed JS error in plugins list
    * Fixed nested folders not synchronizing
    * Fixed issue where Wizard wouldn't work in case the `admin` path was modified (#27, #94, #77, thanks @pathmissing)
    * Fixed webhook generated URL when multi-lang active (#71)
    * Resolved issue with untracked/uncommited files at the root of the `sync` folder. (#101, thanks @ScottHamper)

# v1.0.4
## 08/16/2017

1. [](#new)
    * CLI: Added `status` command to check config and git (#52, thanks @karfau)
    * Allow local branches to be named differently than the remote branches (#48, thanks @denniswebb)
    * Added support for new Admin Navigation Tray
1. [](#bugfix)
    * Fixed minimum Git required version to support `--all` (#32,#49, thanks @redrohX)

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
