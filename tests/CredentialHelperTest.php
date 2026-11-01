<?php

use Grav\Plugin\GitSync\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Run with Grav's PHPUnit and autoloader; no git binary is needed when a version is given.
class CredentialHelperTest extends TestCase
{
    /**
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testCredentialHelperResetNeedsGit29($version, bool $expected): void
    {
        self::assertSame($expected, Helper::canResetCredentialHelper($version));
    }

    public static function versions(): array
    {
        return [
            'git 1.8 rejects the empty value' => ['1.8.3.1', false],
            'git 2.8 ignores it' => ['2.8.6', false],
            'git 2.9.0 resets the list' => ['2.9.0', true],
            'git 2.11' => ['2.11.4', true],
            'current git' => ['2.47.1', true],
            'git not found' => [false, false],
        ];
    }
}
