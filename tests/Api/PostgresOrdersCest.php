<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;
use PHPUnit\Framework\Assert;

use function json_decode;

final readonly class PostgresOrdersCest
{
    public function getOrders(ApiTester $I): void
    {
        $I->sendGET('/postgres/orders');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['status' => 'success']);

        /** @var array{data: list<array<string, mixed>>} $payload */
        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);

        Assert::assertCount(50, $payload['data']);
        Assert::assertSame('ORD-00194399', $payload['data'][0]['reference']);
        Assert::assertSame('customer-14399@example.test', $payload['data'][0]['customer_email']);
        Assert::assertArrayHasKey('customer_name', $payload['data'][0]);
        Assert::assertArrayHasKey('total_cents', $payload['data'][0]);
    }
}
