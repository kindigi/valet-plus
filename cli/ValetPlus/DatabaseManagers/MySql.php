<?php

namespace KinDigi\ValetPlus\DatabaseManagers;

use PDO;
use PDOException;
use PDOStatement;
use Valet\Brew;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;
use function Valet\info;

class MySql extends DatabaseAbstract
{
    private const MYSQL_CONFIG_DIR = 'etc';
    private const MYSQL_CONFIG = 'etc/my.cnf';
    private const MYSQL_DATA_DIR = 'var/mysql';
    private const MAX_FILES_CONFIG = '/Library/LaunchDaemons/limit.maxfiles.plist';

    public const DEFAULT_USER = 'valet';

    public const SYSTEM_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
        'mysql_temp',
    ];

    public const SUPPORTED_VERSIONS = [
        'mysql' => 'MySQL (Latest)',
        'mysql@8.0' => 'MySQL 8.0',
        'mysql@5.7' => 'MySQL 5.7',
        'mariadb' => 'MariaDB (Latest)',
        'mariadb@10.10' => 'MariaDB 10.10',
    ];

    private ?PDO $pdoConnection = null;

    public function __construct(
        public CommandLine   $cli,
        public Filesystem    $files,
        public Configuration $config,
        public Brew          $brew,
    ) {}

    public function install($formula): void
    {
        $installedVersion = $this->installedVersion();
        if ($installedVersion) {
            $name = self::SUPPORTED_VERSIONS[$installedVersion];
            info("The [$name] is already installed.");

            $confirm = confirm(
                label: 'Would you like to reinstall?',
                default: false,
                hint: 'If you choose to reinstall, all databases will be lost.'
            );

            if ($confirm){
                info('Uninstalling [$name] ...');
                $this->uninstall();
            }

            if($installedVersion !== $formula && !$confirm){
                warning("No changes were made.");
                return;
            }
        }

        if (!$installedVersion){
            $this->brew->installOrFail($formula);
        }

        $this->stop();
        $this->installConfiguration($formula);
        $this->copyMaxFilesConfig();
        $this->restart();

        if (str_contains($formula, '@')) {
            $this->brew->link($formula, true);
        }

        if (!$installedVersion){
            $this->createValetUser();
        }else{
            $this->configure();
        }
    }

    public function uninstall()
    {
        $version = $this->installedVersion();
        
        if ($version) {
            $this->brew->stopService($version);
            $this->brew->uninstallFormula($version);
        }
        
        $this->removeConfiguration();
        if ($this->files->exists(BREW_PREFIX.'/'.self::MYSQL_DATA_DIR)) {
            $this->files->rmDirAndContents(BREW_PREFIX.'/'.self::MYSQL_DATA_DIR);
        }
    }

    public function restart()
    {
        $version = $this->installedVersion();
        info("Restarting $version...");

        if ($version) {
            $this->brew->restartService($version);
        }
    }

    public function stop(): void
    {
        $version = $this->installedVersion();
        info("Stopping $version...");

        if ($version) {
            $this->brew->stopService($version);
        }
    }

    public function listDatabase(): bool|array
    {
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('" . implode("','", static::SYSTEM_DATABASES) . "')");
        $query->execute();

        return $query->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function createDatabase($database): bool
    {
        return (bool)$this->query("CREATE DATABASE `$database`");
    }

    public function dropDatabase(string $database): bool
    {
        if ($this->isSystemDatabase($database)) {
            warning("Database `$database` is a system database.");
            return false;
        }

        return (bool)$this->query("DROP DATABASE IF EXISTS `$database`");
    }

    public function resetDatabase(string $database): bool
    {
        return $this->dropDatabase($database) && $this->createDatabase($database);
    }

    public function importDatabase(string $database, string $file): array
    {
        if ($this->isSystemDatabase($database)) {
            warning("Database `$database` is a system database.");
            return [false, 'Database is a system database'];
        }

        $success = true;
        $message = null;

        $gzip = '';
        $sqlFile = '';
        if (stristr($file, '.gz')) {
            $file = escapeshellarg($file);
            $gzip = "zcat {$file} | ";
        } else {
            $file = escapeshellarg($file);
            $sqlFile = " < $file";
        }
        $database = escapeshellarg($database);
        $credentials = $this->getCredentials();
        $this->cli->run(
            sprintf(
                '%smysql -u %s -p%s %s %s',
                $gzip,
                $credentials['user'],
                $credentials['password'] ?? '',
                $database,
                $sqlFile
            ),
            function ($exitCode, $output) use (&$success, &$message) {
                $success = $exitCode;
                if ($exitCode !== 0) {
                    $message = $output;
                }
            }
        );

        return [$success, $message];
    }

    public function exportDatabase($database, $exportDir): array
    {
        $exportAsSql = false;

        $filename = $database . '-' . date('Y-m-d-H-i-s', time());
        $filename = $exportAsSql ? $filename . '.sql' : $filename . '.sql.gz';
        $filename = $exportDir . '/' . $filename;

        $credentials = $this->getCredentials();
        $command = sprintf(
            'mysqldump -u %s -p%s %s %s > %s',
            $credentials['user'],
            $credentials['password'],
            $database,
            $exportAsSql ? '' : '| gzip',
            escapeshellarg($filename)
        );

        $success = true;
        $message = null;

        $this->cli->runAsUser($command, function ($exitCode, $output) use (&$success, &$message) {
            $success = $exitCode;
            if ($exitCode !== 0) {
                $message = $output;
            }
        });

        return [$success, $message];
    }

    public function existsDatabase($database): bool
    {
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$database'");
        $query->execute();

        return (bool)$query->rowCount();
    }

    public function configure(bool $force = false)
    {
        $config = $this->config->read();

        $user = $config['database']['user'] ?? self::DEFAULT_USER;

        if (isset($config['database']['password']) && !$force) {
            $confirm = confirm(
                label: 'Would you like to change the password for the Valet database user?',
                default: false,
            );

            if (!$confirm) {
                return;
            }
        }

        $user = text(
            label: 'Enter the username for the Valet database user',
            default: $user,
            required: true,
        );

        $password = text(
            label: 'Enter the password for the Valet database user',
            hint: 'This password will be used to connect to the databases.',
        );

        $connected = $this->validateConnection($user, $password);
        if (!$connected) {
            $confirm = confirm(
                label: 'Would you like to try again?',
                default: false,
            );

            if ($confirm) {
                $this->configure(true);
            }

            return;
        }

        if (!isset($config['database'])) {
            $config['database'] = [];
        }

        $config['database']['user'] = $user;
        $config['database']['password'] = $password;
        $this->config->write($config);

        info('Configuration updated successfully.');
    }

    private function validateConnection($username, $password): bool
    {
        try {
            // Create connection
            $connection = new \PDO(
                'mysql:host=localhost',
                $username,
                $password
            );
            $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\PDOException $e) {
            error('Invalid database credentials');

            return false;
        }
    }

    private function installConfiguration($formula): void
    {
        info("Updating $formula configuration...");

        $this->files->ensureDirExists(BREW_PREFIX.'/'.self::MYSQL_CONFIG_DIR);

        $content = $this->files->get(__DIR__."/../../stubs/mysql/$formula.cnf");
        $content = str_replace(
            'VALET_HOME_PATH',
            VALET_HOME_PATH,
            $content
        );
        $this->files->putAsUser(
            BREW_PREFIX.'/'.self::MYSQL_CONFIG,
            $content
        );
    }

    private function createValetUser()
    {
        $user = self::DEFAULT_USER;
        $user = text(
            label: 'Enter the username for the Valet database user',
            default: $user,
            required: true,
            hint: 'This user will be created with all privileges on the databases.'
        );

        $password = text(
            label: 'Enter the password for the Valet database user',
            hint: 'This password will be used to connect to the databases.'
        );

        $success = true;
        $errorMessage = "Failed to create user due to [%s]: %s";

        switch ($this->installedVersion()) {
            case 'mysql':
            case 'mysql@8.0':
                $command = sprintf(
                    "sudo mysql -e \"CREATE USER '%s'@'localhost' IDENTIFIED WITH mysql_native_password BY '%s';GRANT ALL PRIVILEGES ON *.* TO '%s'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"",
                    $user,
                    $password,
                    $user
                );
                $this->cli->run($command, function ($exitCode, $output) use (&$success, $errorMessage) {
                    if ($exitCode !== 0) {
                        $success = false;
                        error(sprintf($errorMessage, $exitCode, $output));
                    }
                });
                break;
            case 'mysql@5.7':
            case 'mariadb':
            case 'mariadb@10.10':
                $command = sprintf(
                    "sudo mysql -e \"CREATE USER '%s'@'localhost' IDENTIFIED BY '%s';GRANT ALL PRIVILEGES ON *.* TO '%s'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"",
                    $user,
                    $password,
                    $user
                );
                $this->cli->run($command, function ($exitCode, $output) use (&$success, $errorMessage) {
                    if ($exitCode !== 0) {
                        $success = false;
                        error(sprintf($errorMessage, $exitCode, $output));
                    }
                });
                break;
        }

        if ($success) {
            $config = $this->config->read();

            if (!isset($config['database'])) {
                $config['database'] = [];
            }

            $config['database']['user'] = $user;
            $config['database']['password'] = $password;
            $this->config->write($config);
        }
    }
    private function query($query): bool|PDOStatement|null
    {
        $link = $this->getConnection();

        try {
            return $link->query($query);
        } catch (PDOException $e) {
            warning($e->getMessage());
            return false;
        }
    }

    private function getCredentials()
    {
        $config = $this->config->read();
        if (isset($config['database']['user']) && isset($config['database']['password'])) {
            return $config['database'];
        }else{
            throw new \Exception('Database credentials not found');
        }
    }

    /**
     * Get the mysql connection.
     */
    private function getConnection(): PDO
    {
        // if connection already exists return it early.
        if ($this->pdoConnection) {
            return $this->pdoConnection;
        }

        try {
            // Create connection
            $credentials = $this->getCredentials();

            $this->pdoConnection = new PDO(
                'mysql:host=localhost',
                $credentials['user'],
                $credentials['password']
            );
            $this->pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->pdoConnection;
        } catch (PDOException $e) {
            warning('Failed to connect MySQL due to :`' . $e->getMessage() . '`');
            exit;
        }
    }

    private function installedVersion(): string|bool
    {
        return collect(self::SUPPORTED_VERSIONS)
            ->filter(fn($title, $formula) => $this->brew->installed($formula))
            ->keys()
            ->first(fn($key) => true, false);
    }

    private function removeConfiguration(): void
    {
        $this->files->unlink(BREW_PREFIX.'/'.self::MYSQL_CONFIG);
        $this->files->unlink(BREW_PREFIX.'/'.self::MYSQL_CONFIG.'.default');
    }

    private function copyMaxFilesConfig(): void
    {
        $this->files->copy(
            __DIR__.'/../../stubs/mysql/limit.maxfiles.plist',
            self::MAX_FILES_CONFIG
        );
        $this->cli->quietly('launchctl load -w '.self::MAX_FILES_CONFIG);
    }

    private function isSystemDatabase(string $database): bool
    {
        return in_array($database, static::SYSTEM_DATABASES);
    }
}
