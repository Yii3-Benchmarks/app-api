<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use App\AmpRequestFactory;
use Codeception\Test\Unit;
use HttpSoft\Message\Uri;

final class AmpRequestFactoryTest extends Unit
{
    public function testRequestConversionDoesNotCarryStateIntoNextRequest(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = AmpRequestFactory::create(new Request(
            $client,
            'POST',
            new Uri('http://localhost:9991/postgres/orders?page=2'),
            ['Content-Type' => 'application/json', 'Cookie' => 'session=first'],
            '0',
        ), '0');
        $this->assertSame('http://localhost:9991/postgres/orders?page=2', (string) $request->getUri());
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('0', (string) $request->getBody());
        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['session' => 'first'], $request->getCookieParams());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('127.0.0.1', $request->getServerParams()['REMOTE_ADDR']);
        $this->assertSame(12345, $request->getServerParams()['REMOTE_PORT']);
        $this->assertSame('/postgres/orders?page=2', $request->getServerParams()['REQUEST_URI']);

        $next = AmpRequestFactory::create(new Request($client, 'GET', new Uri('http://localhost/'), protocol: '1.0'), '');
        $this->assertSame('GET', $next->getMethod());
        $this->assertSame('1.0', $next->getProtocolVersion());
        $this->assertSame([], $next->getQueryParams());
        $this->assertSame([], $next->getCookieParams());
        $this->assertSame('', (string) $next->getBody());
    }
}
