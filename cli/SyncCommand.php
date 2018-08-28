<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\GitSync\GitSync;

/**
 * Class LogCommand
 *
 * @package Grav\Plugin\Console
 */
class SyncCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('sync')
            ->setDescription('Performs a synchronization of your site')
            ->setHelp('The <info>sync</info> command performs a synchronization of your site. Useful if you want to run a periodic crontab job to automate it.')
        ;
    }

    protected function serve()
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $plugin = new GitSync();
        $repository = $plugin->getConfig('repository', false);

        $this->output->writeln('');

        if (!$repository) {
            $this->output->writeln('<red>ERROR:</red> No repository has been configured');
        }

        $this->output->writeln('Synchronizing with <cyan>' . $repository . '</cyan>');

        if ($plugin->hasChangesToCommit()) {
            $this->output->writeln('Changes detected, adding and committing...');
            $plugin->add();
            $plugin->commit();
        }

        $this->output->write('Starting Synchronization...');

        $plugin->sync();

        $this->output->writeln('completed.');
    }
}

