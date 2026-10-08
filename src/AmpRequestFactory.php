<?php

declare(strict_types=1);

namespace App;

use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ServerRequestInterface;

/** Converts buffered Amp requests for the benchmark endpoints without modifying globals. */
final class AmpRequestFactory
{
    public static function create(Request $request, string $body): ServerRequestInterface
    {
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $query);
        $cookies = [];
        foreach ($request->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $remote = $request->getClient()->getRemoteAddress();
        return new ServerRequest(
            serverParams: [
                'REQUEST_METHOD' => $request->getMethod(),
                'REQUEST_URI' => $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()),
                'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
                'REMOTE_ADDR' => $remote instanceof InternetAddress ? $remote->getAddress() : (string) $remote,
                'REMOTE_PORT' => $remote instanceof InternetAddress ? $remote->getPort() : 0,
            ],
            cookieParams: $cookies,
            queryParams: $query,
            method: $request->getMethod(),
            uri: $uri,
            headers: $request->getHeaders(),
            body: (new StreamFactory())->createStream($body),
            protocol: $request->getProtocolVersion(),
        );
    }
}
