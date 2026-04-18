<?php

declare(strict_types=1);

use Predis\Client;
use Predis\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\Redis\RedisCache;

/** @var array $params */

return [
    ClientInterface::class => static fn() => new Client($params['yiisoft/cache-redis']['parameters']),
    CacheInterface::class => static fn(ClientInterface $client) => new RedisCache($client),
];
