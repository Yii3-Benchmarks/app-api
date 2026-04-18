<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class PostgresOrdersAction
{
    public function __invoke(
        ResponseFactory $responseFactory,
        ConnectionInterface $db,
    ): ResponseInterface {
        $orders = (new Query($db))
            ->select([
                'o.id',
                'o.reference',
                'o.status',
                'o.total_cents',
                'o.item_count',
                'o.created_at',
                'c.id AS customer_id',
                'c.email AS customer_email',
                'c.full_name AS customer_name',
                'c.segment AS customer_segment',
                'c.city AS customer_city',
            ])
            ->from(['o' => 'orders'])
            ->innerJoin(['c' => 'customers'], 'c.id = o.customer_id')
            ->orderBy([
                'o.created_at' => SORT_DESC,
                'o.id' => SORT_DESC,
            ])
            ->limit(50)
            ->all();

        return $responseFactory->success($orders);
    }
}
