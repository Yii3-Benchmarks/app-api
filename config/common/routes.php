<?php

declare(strict_types=1);

use App\Api;
use Yiisoft\Router\Route;

/**
 * @var array $params
 */

return [
    Route::get('/')->action(Api\IndexAction::class)->name('app/index'),
    Route::get('/postgres/orders')->action(Api\PostgresOrdersAction::class)->name('app/postgres-orders'),
];
