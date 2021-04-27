![](images/gitsync-logo.png)

**Git Sync** is a Plugin for [Grav CMS](http://github.com/getgrav/grav) that allows to seamlessly synchronize a Git repository with your Grav site, and vice-versa. 

Git Sync captures any change that you make on your site and instantly updates your git repository. In the same way, Git Sync supports _webhooks_, allowing to automatically synchronize your site if the repository changes.

Thanks to this powerful bi-directional flow, Git Sync can now turn your site into a collaborative environment where the source of truth is always your git repository and unlimited collaborators and sites can share and contribute to the same content.


## Videos: Setup and Demo

| Up and Running in 2 mins | 2-way Sync Demonstration |
| ------------ | ----------------- |
| [![Up and Running in 2 mins](https://img.youtube.com/vi/avcGP0FAzB8/0.jpg)](https://www.youtube.com/watch?v=avcGP0FAzB8) | [![2-way Sync Demonstration](https://img.youtube.com/vi/3fy78afacyw/0.jpg)](https://www.youtube.com/watch?v=3fy78afacyw) |

## Installation using the GPM (Grav Package Manager)

To install git-sync simply run this command from the Grav root folder
```
bin/gpm install git-sync
```

After having installed the plugin, make sure to go in the plugin settings in order to get the Wizard configuration started.


## Features

<img src="wizard.png" width="500" />
 
* Easy step-by-step Wizard setup will guide you through a detailed process for setting things up
* Supported hosting services: [GitHub](https://github.com), [BitBucket](https://bitbucket.org), [GitLab](https://gitlab.com) as well as any self-hosted and git service with webhooks support.
* Private repositories
* Basic SSH / Enterprise support (You will need SSH Key properly setup on your machine)
* Synchronize any folder under `user` (pages, themes, config) 
* 2FA (Two-Factor Authentication) and Access Token support
* Webhooks support allow for automatic synchronization from the Git Repository with secure Webhook URL auto-generated and support for Webhook Secret (when available)
* Automatically handles simple merges behind the scenes
* Easy one-click button to reset your local changes and restores it to the actual state of the git repository
* Easy one-click button for manual synchronization
* Support for Admin Quick Tray, so you can synchronize from anywhere in Admin
* Ability to customize whether GitSync should synchronize upon save or just manually
* Customize the Committer Name, choose between Git User, GitSync Commiter Name, Grav User Name and Grav user Fullname 
* With the built-in Form Process action `gitsync`, you can trigger the synchronization anytime someone submits a post.
* Any 3rd party plugin can integrate with Git Sync and trigger the synchronization through the `gitsync` event.
* Built-in CLI commands to automate synchronizations
* Log any command performed by GitSync to ensure everything runs smoothly or debug potential issues

# Command Line Interface

Git Sync comes with a CLI that allows running synchronizations right within your terminal. This feature is extremely useful in case you'd like to run an autonomous periodic crontab jobs to synchronize with your repository.

To execute the command simply run:

```bash
bin/plugin git-sync sync
```

You can also get a status by running:
```bash
bin/plugin git-sync status
```

Since version 2.1.1 you can now also programmatically change user/password via the `bin/plugin git-sync passwd`. This is useful if you have a container that resets your password, or you have some running scripts that require to programmatically update the password.

# Requirements

In order for the plugin to work, the server needs to run `git` 1.7.1 and above. 

The PHP `exec()` and `escapeshellarg()` functions are mandatory. Ensure the options to be enabled in your PHP.

# SSH / Enterprise

Since version v2.3.0, GitSync supports SSH authentication. This means you can omit password altogether and rely on the Repository URL and SSH key on your machine, that you can point to from the Advanced settings in GitSync.

Please note that In order to be able to sparse-checkout and push changes, it is expected you have an ssh-key configured for accessing the repository. This is usually found under `~/.ssh` and it needs to be configured for the same user that runs the web-server.

Point it to the secret (not the public) and make also sure you have strict permissions to the file. (`-rw-------`).

Example: private_key: `/home/www-data/.ssh/id_rsa`

> **IMPORTANT**: SSH keys with passphrase are **NOT** supported. To remove a passphrase, run the `ssh-keygen -p` command and when asked for the new passphrase leave blank and return.

# Known Issues and Resolutions
**Q:** `error: The requested URL returned error: 403 Forbidden while accessing...` [#39](https://github.com/trilbymedia/grav-plugin-git-sync/issues/39)  
**A:** This might be caused by your computer having stored in the registry a user/password that might conflict with the one you are intending to use.  
[Follow the instructions for resolving the issue...](https://github.com/trilbymedia/grav-plugin-git-sync/issues/39#issuecomment-538867548)

# Sponsored by

This plugin could not have been realized without the sponsorship of [Hibbitts Design](http://www.hibbittsdesign.org/blog/) and the development of [Trilby Media](http://trilby.media).
