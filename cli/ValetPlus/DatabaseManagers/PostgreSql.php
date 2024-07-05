<?php

namespace KinDigi\ValetPlus\DatabaseManagers;

class PostgreSql extends DatabaseAbstract implements DatabaseInterface
{
    public string $manager = 'postgresql';

    public function install($package)
    {
        // TODO: Implement install() method.
    }

    public function uninstall()
    {
        // TODO: Implement uninstall() method.
    }

    public function restart()
    {
        // TODO: Implement restart() method.
    }

    public function stop()
    {
        // TODO: Implement stop() method.
    }

    public function listDatabase()
    {
        // TODO: Implement listDatabase() method.
    }

    public function createDatabase()
    {
        // TODO: Implement createDatabase() method.
    }

    public function dropDatabase()
    {
        // TODO: Implement dropDatabase() method.
    }

    public function resetDatabase(?string $database = null)
    {
        // TODO: Implement resetDatabase() method.
    }

    public function importDatabase(?string $database = null, ?string $file = null, bool $force = false)
    {
        // TODO: Implement importDatabase() method.
    }

    public function exportDatabase(?string $database = null)
    {
        // TODO: Implement exportDatabase() method.
    }

    public function existsDatabase($database): bool
    {
        return true;
    }
}