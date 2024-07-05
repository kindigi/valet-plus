<?php

namespace KinDigi\ValetPlus\DatabaseManagers;

abstract class DatabaseAbstract
{
    abstract public function install($formula);
    abstract public function uninstall();
    abstract public function restart();
    abstract public function stop();
    abstract public function listDatabase();
    abstract public function createDatabase(string $database): bool;
    abstract public function dropDatabase(string $database): bool;
    abstract public function resetDatabase(string $database): bool;
    abstract public function importDatabase(string $database, string $file);
    abstract public function exportDatabase(string $database, string $exportDir);
    abstract public function existsDatabase($database): bool;
}