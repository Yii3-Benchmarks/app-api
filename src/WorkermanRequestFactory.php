<?php

declare(strict_types=1);

namespace App;

use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ServerRequestInterface;
use Workerman\Protocols\Http\Request;

/** Converts requests for the benchmark's GET endpoints without modifying globals. */
final class WorkermanRequestFactory
{
    public static function create(Request $request, string $remoteAddress, int $remotePort): ServerRequestInterface
    {
        return new ServerRequest(
            serverParams: [
                'REQUEST_METHOD' => $request->method(),
                'REQUEST_URI' => $request->uri(),
                'SERVER_PROTOCOL' => 'HTTP/' . $request->protocolVersion(),
                'REMOTE_ADDR' => $remoteAddress,
                'REMOTE_PORT' => $remotePort,
            ],
            cookieParams: $request->cookie(),
            queryParams: $request->get(),
            parsedBody: $request->post(),
            method: $request->method(),
            uri: 'http://' . $request->host() . $request->uri(),
            headers: $request->header(),
            body: (new StreamFactory())->createStream($request->rawBody()),
            protocol: $request->protocolVersion(),
        );
    }
}
