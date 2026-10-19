<?php

// Creates a new disposable schema. Never migrates or clears the configured application database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = config('database.connections.mysql');
$pdo = new PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'karm_employee_test_'.bin2hex(random_bytes(8));
if (! preg_match('/^karm_employee_test_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Unsafe test schema name');
}
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$environment = array_merge(getenv(), [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
    'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'BCRYPT_ROUNDS' => '4',
    'EMPLOYEE_TEST_DATABASE' => $database,
]);
$root = dirname(__DIR__, 2);
$run = function (array $command) use ($root, $environment): int {
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, $environment);
    if (! is_resource($process)) { throw new RuntimeException('Cannot launch test process'); }
    return proc_close($process);
};
$exit = 1;
try {
    echo "Disposable schema: $database\n";
    $exit = $run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction']);
    if ($exit === 0) {
        $exit = $run([PHP_BINARY, 'vendor/bin/phpunit', '-c', 'phpunit.mysql.xml', '--filter', 'EmployeeOrderAccessTest|test_order_ownership_admin_access_and_paginated_summaries|test_every_list_validates_pagination_and_preserves_security|test_management_routes_preserve_auth_active_and_scoped_table_reads|test_tables_create_update_and_status_protection']);
    }
} finally {
    // Only the exact schema created by this process is ever removed.
    $pdo->exec("DROP DATABASE `$database`");
    echo "Disposable schema removed. Application database untouched.\n";
}
exit($exit);
