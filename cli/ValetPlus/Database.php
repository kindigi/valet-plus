<?php

namespace KinDigi\ValetPlus;

use KinDigi\ValetPlus\DatabaseManagers\DatabaseFactory;
use Valet\Brew;
use Valet\Configuration;
use Valet\Filesystem;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\table;

class Database
{
    public function __construct(
        public Brew $brew,
        public Configuration $config,
        public Filesystem $files,
    )
    {

    }

    public function install()
    {
        $manager = select(
            label: 'Which database would you like to use?',
            options: [
                'mysql' => 'MySQL',
                'mariadb' => 'MariaDB',
                'postgresql' => 'PostgreSQL',
                'none' => 'None',
            ],
        );

        if ($manager === 'none') {
            return;
        }

        $formula = null;
        switch ($manager) {
            case 'mysql':
                $formula = select(
                    label: 'Which version of MySQL would you like to install?',
                    options: [
                        'mysql' => 'MySQL (Latest)',
                        'mysql@8.0' => 'MySQL 8.0',
                        'mysql@5.7' => 'MySQL 5.7',
                    ],
                    required: true
                );
                break;
            case 'mariadb':
                $formula = select(
                    label: 'Which version of MariaDB would you like to install?',
                    options: [
                        'mariadb' => 'MariaDB (Latest)',
                        'mariadb@10.10' => 'MariaDB 10.10',
                    ],
                    required: true
                );
                break;
            case 'postgresql':
                $formula = select(
                    label: 'Which version of PostgreSQL would you like to install?',
                    options: [
                        'postgresql@16' => 'PostgreSQL (Latest)',
                        'postgresql@15.7' => 'PostgreSQL 15.7',
                    ],
                    required: true
                );
                break;
        }

        $databaseManager = DatabaseFactory::manager($manager);
        $databaseManager->install($formula);

        $this->setDatabaseManagerConfig($manager, $formula);
    }

    public function listDatabase(): void
    {
        $manager = DatabaseFactory::manager();
        $databases = $manager->listDatabase();

        if (empty($databases)) {
            info('No databases found');
            return;
        }

        table(['Database'], collect($databases)->map(fn($database) => `$database`)->toArray());
    }

    public function createDatabase(string|null $database = null): void
    {
        if (!$database){
            $database = text(
                label: "Enter the name of the database",
                required: true
            );
        }

        $manager = DatabaseFactory::manager();

        if ($manager->existsDatabase($database)) {
            warning("Database `$database` already exists");

            $confirm = confirm("Would you like to try a different name?");

            if ($confirm) {
                $this->createDatabase();
            }
            return;
        }

        $isCreated = $manager->createDatabase($database);
        if ($isCreated) {
            info("Database `$database` created successfully");
        } else {
            warning("Failed to create database `$database`");
        }
    }

    public function dropDatabase(string|null $database = null, $force = false): void
    {
        $manager = DatabaseFactory::manager();
        if (!$database){
            $databases = $manager->listDatabase();

            if (empty($databases)) {
                info('No databases found');
                return;
            }

            $database = select(
                label: 'Which database would you like to drop?',
                options: $databases,
            );
        }

        if (!$manager->existsDatabase($database)) {
            warning("Database `$database` does not exist");
            return;
        }

        if (!$force) {
            $confirm = confirm("Are you sure you want to drop the database `$database`?", false);
            if (!$confirm) {
                warning("Database `$database` was not dropped");
                return;
            }
        }

        $isDropped = $manager->dropDatabase($database);
        if ($isDropped) {
            info("Database `$database` dropped successfully");
        } else {
            warning("Failed to drop database `$database`");
        }
    }

    public function resetDatabase(string|null $database = null, bool $force = false)
    {
        $manager = DatabaseFactory::manager();
        if (!$database){
            $databases = $manager->listDatabase();

            if (empty($databases)) {
                info('No databases found');
                return;
            }

            $database = select(
                label: 'Which database would you like to reset?',
                options: $databases,
            );
        }

        if (!$manager->existsDatabase($database)) {
            warning("Database `$database` does not exist");
            return;
        }

        if (!$force) {
            $confirm = confirm(
                label: "Are you sure you want to reset the database `$database`?",
                default: false,
                hint: "This will drop all tables and data in the database"
            );
            if (!$confirm) {
                warning("Database `$database` was not reset");
                return;
            }
        }

        $isReset = $manager->resetDatabase($database);

        if ($isReset) {
            info("Database `$database` reset successfully");
        } else {
            warning("Failed to reset database `$database`");
        }
    }

    public function importDatabase(string|null $database = null, string|null $file = null, bool $force = false): void
    {
        $manager = DatabaseFactory::manager();
        if (!$database) {
            $databases = $manager->listDatabase();

            if (empty($databases)) {
                warning('No MySQL databases found.');
                return;
            }

            $database = select(
                label: 'Which database would you like to import to?',
                options: $databases,
                hint: 'Use arrow keys to navigate, press ↵ to select.'
            );
        }

        if (!$file) {
            $file = text(
                label: 'Enter the path to the SQL file:',
                placeholder: 'e.g. /path/to/file.sql'
            );
        }

        if (!$this->files->exists($file)) {
            warning("The file `$file` does not exist.");
            return;
        }

        if ($manager->existsDatabase($database)) {
            if (!$force) {
                $question = confirm('The database already exists. Do you want to continue?');

                if (!$question) {
                    warning('No MySQL databases were imported.');
                    return;
                }
            }
        } else {
            $this->createDatabase($database);
        }

        [$success, $message] = $manager->importDatabase($database, $file);

        if ($success === true){
            info("The `$file` file has been imported to the `$database` database.");
        }else{
            error("Failed to import the `$file` file to the `$database` database.");
            error($message);
        }
    }

    public function exportDatabase(string|null $database): void
    {
        $manager = DatabaseFactory::manager();
        if (!$database) {
            $databases = $manager->listDatabase();

            if (empty($databases)) {
                warning('No MySQL databases found.');
                return;
            }

            $database = select(
                label: 'Which database would you like to export?',
                options: $databases,
                hint: 'Use arrow keys to navigate, press ↵ to select.'
            );
        }

        $exportDir = getcwd();

        $isExportToCurrentDir = confirm(
            label: "Would you like to export the database to the current directory?",
            hint: "Directory: $exportDir"
        );

        if (!$isExportToCurrentDir) {
            $exportDir = text(
                label: 'Enter the path to the directory where the database will be exported:',
                placeholder: 'e.g. /path/to/directory',
                required: true
            );
        }

        $this->files->ensureDirExists($exportDir);

        if (!is_writable($exportDir)) {
            warning("The current directory is not writable.");
            return;
        }

        [$success, $message] = $manager->exportDatabase($database, $exportDir);

        if ($success === true) {
            info("The `$database` database has been exported to the `$exportDir` directory.");
        } else {
            error("Failed to export the `$database` database to the `$exportDir` directory.");
            error($message);
        }
    }

    public function configure()
    {
        $manager = DatabaseFactory::manager();
        $manager->configure();
    }

    private function setDatabaseManagerConfig($manager, $formula): void
    {
        $config = $this->config->read();

        if (!isset($config['database'])) {
            $config['database'] = [];
        }

        $config['database']['manager'] = $manager;
        $config['database']['formula'] = $formula;
        $this->config->write($config);
    }
}