<?php
/*
 * This file is part of Git.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SebastianBergmann\Git;

use DateTime;

/**
 */
class Git
{
    /**
     * @var string
     */
    private $repositoryPath;

    /**
     * @param string $repositoryPath
     */
    public function __construct($repositoryPath)
    {
        if (!is_dir($repositoryPath)) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" does not exist',
                    $repositoryPath
                )
            );
        }

        $this->repositoryPath = realpath($repositoryPath);
    }

    /**
     * @param string $revision
     */
    public function checkout($revision)
    {
        $this->execute(
            'checkout --force --quiet ' . $revision . ' 2>&1'
        );
    }

    /**
     * @return string
     */
    public function getCurrentBranch()
    {
        $output = $this->execute('symbolic-ref --short HEAD');

        return $output[0];
    }

    /**
     * @param  string $from
     * @param  string $to
     * @return string
     */
    public function getDiff($from, $to)
    {
        $output = $this->execute(
            'diff --no-ext-diff ' . $from . ' ' . $to
        );

        return implode("\n", $output);
    }

    /**
     * @return array
     */
    public function getRevisions()
    {
        $output = $this->execute(
            'log --no-merges --date-order --reverse --format=medium'
        );

        $numLines  = count($output);
        $revisions = array();

        for ($i = 0; $i < $numLines; $i++) {
            $tmp = explode(' ', $output[$i]);

            if ($tmp[0] == 'commit') {
                $sha1 = $tmp[1];
            } elseif ($tmp[0] == 'Author:') {
                $author = implode(' ', array_slice($tmp, 1));
            } elseif ($tmp[0] == 'Date:' && isset($author) && isset($sha1)) {
                $revisions[] = array(
                  'author'  => $author,
                  'date'    => DateTime::createFromFormat(
                      'D M j H:i:s Y O',
                      implode(' ', array_slice($tmp, 3))
                  ),
                  'sha1'    => $sha1,
                  'message' => isset($output[$i+2]) ? trim($output[$i+2]) : ''
                );

                unset($author);
                unset($sha1);
            }
        }

        return $revisions;
    }

    /**
     * @return bool
     */
    public function isWorkingCopyClean()
    {
        $output = $this->execute('status');

        return $output[count($output)-1] == 'nothing to commit, working directory clean';
    }

    /**
     * @param  string           $command
     * @throws RuntimeException
     */
    protected function execute($command)
    {
        $command = 'git -C ' . escapeshellarg($this->repositoryPath) . ' ' . $command;
        if (DIRECTORY_SEPARATOR == '/') {
            $command = 'LC_ALL=en_US.UTF-8 ' . $command;
        }
        exec($command, $output, $returnValue);

        if ($returnValue !== 0) {
            throw new RuntimeException(implode("\r\n", $output));
        }

        return $output;
    }
}
