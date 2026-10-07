<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\SwooleRequestFactory;
use Codeception\Test\Unit;

final class SwooleRequestFactoryTest extends Unit
{
    public function testRequestConversionDoesNotCarryStateIntoNextRequest(): void
    {
        $incoming = new class {
            public array $server = ['request_method' => 'POST', 'request_uri' => '/postgres/orders', 'query_string' => 'page=2', 'server_protocol' => 'HTTP/1.1', 'remote_addr' => '127.0.0.1'];
            public array $header = ['host' => 'localhost:9991', 'content-type' => 'application/json'];
            public array $get = ['page' => '2'];
            public array $cookie = ['session' => 'first'];
            public function rawContent(): string
            {
                return '0';
            }
        };
        $request = SwooleRequestFactory::create($incoming);
        $this->assertSame('http://localhost:9991/postgres/orders?page=2', (string) $request->getUri());
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame('0', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['session' => 'first'], $request->getCookieParams());
        $this->assertSame('127.0.0.1', $request->getServerParams()['REMOTE_ADDR']);

        $next = SwooleRequestFactory::create(new class {
            public function rawContent(): false
            {
                return false;
            }
        });
        $this->assertSame('http://localhost/', (string) $next->getUri());
        $this->assertSame('GET', $next->getMethod());
        $this->assertSame([], $next->getCookieParams());
        $this->assertSame([], $next->getQueryParams());
        $this->assertSame('', (string) $next->getBody());
    }
}
