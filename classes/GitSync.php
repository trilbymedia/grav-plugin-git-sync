<?php
namespace Grav\Plugin\GitSync;

use SebastianBergmann\Git\Git;

class GitSync extends Git
{
    static public $instance = null;

    public function __construct()
    {
        parent::__construct(USER_DIR);
        static::$instance = $this;
    }

    static public function instance()
    {
        return static::$instance = is_null(static::$instance) ? new static : static::$instance;
    }

    public function testRepository($url)
    {
        return $this->execute("ls-remote '${url}'");
    }

    public function execute($command)
    {
        return parent::execute($command . ' 2>&1');
    }
}
