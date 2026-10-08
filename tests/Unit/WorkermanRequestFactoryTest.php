<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\WorkermanRequestFactory;
use Codeception\Test\Unit;
use Workerman\Protocols\Http\Request;

final class WorkermanRequestFactoryTest extends Unit
{
    public function testRequestConversionDoesNotCarryStateIntoNextRequest(): void
    {
        $incoming = new Request("POST /postgres/orders?page=2 HTTP/1.1\r\nHost: localhost:9991\r\nContent-Type: application/json\r\nCookie: session=first\r\nContent-Length: 1\r\n\r\n0");
        $request = WorkermanRequestFactory::create($incoming, '127.0.0.1', 12345);
        $this->assertSame('http://localhost:9991/postgres/orders?page=2', (string) $request->getUri());
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame('0', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['session' => 'first'], $request->getCookieParams());
        $this->assertSame('127.0.0.1', $request->getServerParams()['REMOTE_ADDR']);
        $this->assertSame(12345, $request->getServerParams()['REMOTE_PORT']);

        $next = WorkermanRequestFactory::create(new Request("GET / HTTP/1.0\r\nHost: localhost\r\n\r\n"), '127.0.0.2', 54321);
        $this->assertSame('http://localhost/', (string) $next->getUri());
        $this->assertSame('GET', $next->getMethod());
        $this->assertSame('1.0', $next->getProtocolVersion());
        $this->assertSame([], $next->getCookieParams());
        $this->assertSame([], $next->getQueryParams());
        $this->assertSame('', (string) $next->getBody());
        $this->assertSame('127.0.0.2', $next->getServerParams()['REMOTE_ADDR']);
    }
}
