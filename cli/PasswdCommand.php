<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\GitSync\GitSync;
use Grav\Plugin\GitSync\Helper;
use Grav\Common\Grav;
use RocketTheme\Toolbox\File\YamlFile;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class LogCommand
 *
 * @package Grav\Plugin\Console
 */
class PasswdCommand extends ConsoleCommand
{
    /** @var array */
    protected $options = [];

    protected function configure()
    {
        $this
            ->setName('passwd')
            ->setDescription('Allows to change the user and/or password programmatically')
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'The username. Use empty double quotes if you need an empty username.'
            )
            ->addOption(
                'password',
                'p',
                InputOption::VALUE_REQUIRED,
                "The password."
            )
            ->setHelp('The <info>%command.name%</info> command allows to change the user and/or password. Useful when running automated scripts or needing to programmatically set them without admin access.')
        ;
    }

    protected function serve()
    {
        require_once __DIR__ . '/../vendor/autoload.php';
        $grav = Grav::instance();
        $config = $grav['config'];
        $locator = $grav['locator'];
        $filename = 'config://plugins/git-sync.yaml';
        $file = YamlFile::instance($locator->findResource($filename, true, true));

        $this->options = [
            'user'       => $this->input->getOption('user'),
            'password'   => $this->input->getOption('password')
        ];

        if ($this->options['password'] !== null) {
            $this->options['password'] = Helper::encrypt($this->options['password']);
        }

        $user = $this->options['user'] !== null ? $this->options['user'] : $config->get('plugins.git-sync.user');
        $password = $this->options['password'] !== null ? $this->options['password'] : $config->get('plugins.git-sync.password');

        $config->set('plugins.git-sync.user', $user);
        $config->set('plugins.git-sync.password', $password);

        $content = $grav['config']->get('plugins.git-sync');
        $file->save($content);
        $file->free();

        $this->output->writeln('');
        $this->output->writeln('<green>User / Password updated.</green>');
        $this->output->writeln('');
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
