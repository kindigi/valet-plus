<?php

namespace KinDigi\ValetPlus\DatabaseManagers;

use Valet\Brew;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;

class DatabaseFactory
{
    public static function manager($manager = null): PostgreSql|MySql
    {
        $commandLine = new CommandLine();
        $filesystem = new Filesystem();
        $configuration = new Configuration($filesystem);
        $brew = new Brew($commandLine, $filesystem);

        if (!$manager){
            $config = $configuration->read();
            $manager = $config['database']['manager'];
        }

        return match ($manager) {
            'mysql' => new MySql(
                cli: $commandLine,
                files: $filesystem,
                config: $configuration,
                brew: $brew
            ),
            'postgresql' => new PostgreSql(),
            default => throw new \DomainException('Database manager not supported'),
        };
    }
}