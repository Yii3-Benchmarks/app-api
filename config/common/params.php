<?php

declare(strict_types=1);

use App\Environment;
use Yiisoft\Db\Pgsql\Dsn;

return [
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/cache-redis' => [
        'parameters' => [
            'scheme' => 'tcp',
            'host' => Environment::valkeyHost(),
            'port' => Environment::valkeyPort(),
            'database' => Environment::valkeyDatabase(),
            'persistent' => true,
        ],
    ],

    'yiisoft/db-pgsql' => [
        'dsn' => new Dsn(
            'pgsql',
            Environment::pgsqlHost(),
            Environment::pgsqlDatabase(),
            Environment::pgsqlPort()
        ),
        'username' => Environment::pgsqlUsername(),
        'password' => Environment::pgsqlPassword(),
    ],
];
