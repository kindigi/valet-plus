<?php

namespace KinDigi\ValetPlus\Extends;
use KinDigi\ValetPlus\PhpExtension;
use Valet\Brew;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm as LaravelPhpFpm;
use Valet\Site;

class PhpFpm extends LaravelPhpFpm
{
    public function __construct(
        public Brew $brew,
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config,
        public Site $site,
        public Nginx $nginx,
        public PhpExtension $phpExtension
    ) {
        parent::__construct($brew, $cli, $files, $config, $site, $nginx);
    }
}