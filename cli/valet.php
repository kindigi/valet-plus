<?php

use Silly\Application;
use function Laravel\Prompts\{error, info};

$valetDir = __DIR__ . '/../vendor/laravel/valet';
if (!file_exists($valetDir)) {
    $valetDir = __DIR__ . '/../../laravel/valet';
}

require_once $valetDir . '/cli/app.php';
require_once __DIR__ . '/includes/facades.php';
require_once __DIR__ . '/includes/extends.php';

/**
 * @var Application $app
 */
$version = '1.0.0';
$app->setName('Valet +');
$app->setVersion($version);

// Get the installation command from the original Valet
$installCommand = $app->get('install');

$app->command('install', function () use ($installCommand) {
    Database::install();
    info('Installing Valet+');
})->descriptions('Install Valet+');

$app->command('db:list', function () {
    Database::listDatabase();
})->descriptions('List databases');

$app->command('db:create [name]', function ($name) {
    Database::createDatabase($name);
})->descriptions('Create a new database', [
    'name' => 'The name of the database to create',
]);

$app->command('db:drop [name] [--force]', function ($name, $force) {
    Database::dropDatabase($name, $force);
})->descriptions('Drop a database', [
    'name' => 'The name of the database to drop',
    '--force' => 'Force the drop without confirmation',
]);

$app->command('db:reset [name] [--force]', function ($name) {
    Database::resetDatabase($name);
})->descriptions('Reset a database', [
    'name' => 'The name of the database to reset',
    '--force' => 'Force the reset without confirmation',
]);

$app->command('db:import [name] [file] [--force]', function ($name, $file) {
    Database::importDatabase($name, $file);
})->descriptions('Import a database', [
    'name' => 'The name of the database to import to',
    'file' => 'The path to the SQL file to import',
    '--force' => 'Force the import without confirmation',
]);

$app->command('db:export [name]', function ($name) {
    Database::exportDatabase($name);
})->descriptions('Export a database', [
    'name' => 'The name of the database to export',
]);

$app->command('db:configure', function () {
    Database::configure();
})->descriptions('Configure database settings');

try {
    $app->run();
} catch (Exception $e) {
    error($e->getMessage());
}