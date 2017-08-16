<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\GitSync\GitSync;
use Grav\Plugin\GitSync\Helper;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class LogCommand
 *
 * @package Grav\Plugin\Console
 */
class StatusCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->setDescription('Checks the status of plugin config, git and git workspace. No files get modified!')
            ->addOption(
              'fetch', 'f',
              InputOption::VALUE_NONE,
              'additionally do a git fetch to look updates (changes not files in workspace)'
            )
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command checks if the plugin is usable the way it has been configured.
While doing this it prints the available information for your inspection.

<comment>No files in the workspace are modified when running this test.</comment>

The <info>--fetch</info> option can be used to see differences between the remote in the <info>git status</info> (last check) 

It also returns with an error code and a helpful message when something is not normal:

  <error>100</error> : <info>git</info> binary not working as expected
  <error>50</error>  : <info>repositoryFolder</info> and git workspace root do not match
  <error>10</error>  : <info>repository</info> is not configured
  <error>5</error>   : state of workspace not clean
  <error>1</error>   : Some checks can throw a <info>RuntimeException</info> which is not caught, read the message for details

EOF
)
        ;
    }

    protected function serve()
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $plugin = new GitSync();
        $this->output->writeln('');


        $this->console_header('plugin runtime information:');
        $info = $plugin->getRuntimeInformation();
        $info['isGitInitialized'] = Helper::isGitInitialized();
        $info['gitVersion'] = Helper::isGitInstalled(true);
        ksort($info);
        dump($info);
        if (!Helper::isGitInstalled()) {
          throw new RuntimeException('git binary not found', 100);
        }

        $this->console_header('detect git workspace root:');
        $git_root = $plugin->execute('rev-parse --show-toplevel');
        $this->console_log($git_root, '');
        if (rtrim($info['repositoryPath'], '/') !== rtrim($git_root[0], '/')) {
            throw new RuntimeException('git root and repositoryPath do not match', 50);
        }

        // needed to prevent out put in logs:
        $password = Helper::decrypt($plugin->getPassword());


        $this->console_header('local git config:');
        $this->console_log(
          $plugin->execute('config --local -l'), $password
        );


        $this->console_header(
          'Testing connection to repository', 'git ls-remote', true
        );
        $repository = $plugin->getConfig('repository', false);
        if (!$repository) {
          throw new RuntimeException('No repository has been configured', 10);
        }
        $testRepository = $plugin->testRepository(
            Helper::prepareRepository(
              $plugin->getUser(),
              $password,
              $repository)
        );
        $this->console_log($testRepository, $password);

        $fetched = false;
        if ($this->input->getOption('fetch')) {
            $remote = $plugin->getRemote('name', '');
            $this->console_header(
              'Looking for updates', "git fetch $remote", true
            );
            $this->console_log($plugin->fetch($remote), $password);
            $fetched = true;
        }

        $this->console_header(
          'Checking workspace status', 'git status', true
        );
        $git_status = $plugin->execute('status');
        $this->console_log($git_status, $password);
        if (!$plugin->isWorkingCopyClean()) {
          throw new RuntimeException('Working state is not clean.', 5);
        }


        if ($fetched) {
          $uptodate = strpos($git_status[1], 'branch is up-to-date with') > 0;
          if ($uptodate) {
            $this->console_header(
              'Congrats: You should be able to run the <info>sync</info> command without problems!'
            );
          } else {
            $this->output->writeln('<yellow>You are not in sync!</yellow>');
            $this->output->writeln('Take a look at the output of git status to see more details.');
            $this->output->writeln('In most cases the <info>sync</info> command is able to fix this.');
          }
        } else {
          $this->console_header('Looks good: use <info>--fetch</info> option to check for updates.');
        }
    }

    private function console_header($readable, $cmd = '', $remote_action = false)
    {
        $this->output->writeln(
            "<yellow>$readable</yellow>" . ($cmd ? "(<info>$cmd</info>)" : ''). ($remote_action ? '...' : '')
        );
    }

    private function console_log($lines, $password)
    {
        foreach ($lines as $line) {
            $this->output->writeln('  ' . Helper::preventReadablePassword($line, $password));
        }
    }


}
