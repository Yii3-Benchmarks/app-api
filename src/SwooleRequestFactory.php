<?php

declare(strict_types=1);

namespace App;

use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ServerRequestInterface;

/** Converts Swoole requests for the benchmark's GET endpoints without modifying globals. */
final class SwooleRequestFactory
{
    public static function create(object $request): ServerRequestInterface
    {
        $server = array_change_key_case($request->server ?? [], CASE_UPPER);
        $headers = $request->header ?? [];
        $path = $server['REQUEST_URI'] ?? '/';
        $query = $server['QUERY_STRING'] ?? '';
        $uri = 'http://' . ($headers['host'] ?? 'localhost') . $path . ($query === '' ? '' : '?' . $query);

        return new ServerRequest(
            serverParams: $server,
            cookieParams: $request->cookie ?? [],
            queryParams: $request->get ?? [],
            parsedBody: $request->post ?? null,
            method: $server['REQUEST_METHOD'] ?? 'GET',
            uri: $uri,
            headers: $headers,
            body: (new StreamFactory())->createStream((string) $request->rawContent()),
            protocol: substr($server['SERVER_PROTOCOL'] ?? 'HTTP/1.1', 5),
        );
    }
}
