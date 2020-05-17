<?php namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\GitSync\GitSync;

/**
 * Class InitCommand
 *
 * @package Grav\Plugin\Console
 */
class InitCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Initializes your git repository')
            ->setHelp('The <info>init</info> command runs the same git commands as the onAdminAfterSave function. Use this to manually initialize git-sync (useful for automated deployments).')
        ;
    }

    protected function serve()
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $plugin = new GitSync();
        $repository = $plugin->getConfig('repository', false);

        $this->output->writeln('');

        if (!$repository) {
            $this->output->writeln('<red>ERROR:</red> No repository has been configured!');
        }

        $this->output->writeln('Initializing <cyan>' . $repository . '</cyan>');

        $this->output->write('Starting initialization...');

        $plugin->initializeRepository();
        $plugin->setUser();
        $plugin->addRemote();

        $this->output->writeln('completed.');
    }
}
