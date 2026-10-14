<?php

use Fleetbase\Storefront\Support\QPay;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

/** A QPay client for one merchant, answering from canned responses. */
function qpayTokenCacheClient(string $username, array $responses, array &$history): QPay
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));

    $qpay = new QPay($username, 'token-cache-secret', 'https://storefront.test/qpay');
    $qpay->updateRequestOption('handler', $handler);

    return $qpay;
}

function qpayTokenCacheKey(string $username): string
{
    return 'storefront:qpay:token:' . sha1('https://merchant.qpay.mn/|' . $username . '|token-cache-secret');
}

test('a qpay token is kept until shortly before it expires and reused by the next client of the same merchant', function () {
    $merchant  = 'token-cache-merchant-' . uniqid();
    $expiresAt = time() + 86400;
    $first     = [];
    $second    = [];

    qpayTokenCacheClient($merchant, [
        new Response(200, [], json_encode(['access_token' => 'kept-token', 'expires_in' => $expiresAt])),
    ], $first)->setAuthToken();

    $client = qpayTokenCacheClient($merchant, [new Response(200, [], '{"ok":true}')], $second);
    $client->setAuthToken();
    $client->get('health');

    expect($first)->toHaveCount(1)
        ->and((string) $first[0]['request']->getUri())->toEndWith('/v2/auth/token')
        ->and(Cache::get(qpayTokenCacheKey($merchant)))->toBe(['token' => 'kept-token', 'expires_at' => $expiresAt])
        // The second client asks for no token of its own.
        ->and($second)->toHaveCount(1)
        ->and((string) $second[0]['request']->getUri())->toEndWith('/v2/health')
        ->and($second[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer kept-token');
});

test('a kept token qpay no longer accepts is forgotten, replaced and the request tried once more', function () {
    $merchant = 'token-cache-merchant-' . uniqid();
    Cache::put(qpayTokenCacheKey($merchant), ['token' => 'revoked-token', 'expires_at' => time() + 86400], 3600);
    $history = [];

    $client = qpayTokenCacheClient($merchant, [
        new Response(401, [], '{"error":"NO_CREDENTIALS"}'),
        new Response(200, [], json_encode(['access_token' => 'renewed-token', 'expires_in' => time() + 7200])),
        new Response(200, [], '{"ok":true}'),
    ], $history);
    $client->setAuthToken();

    expect($client->get('health')->ok)->toBeTrue()
        ->and($history)->toHaveCount(3)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer revoked-token')
        // The new token is requested with the merchant credentials, not the rejected token.
        ->and((string) $history[1]['request']->getUri())->toEndWith('/v2/auth/token')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toStartWith('Basic ')
        ->and($history[2]['request']->getHeaderLine('Authorization'))->toBe('Bearer renewed-token')
        ->and(Cache::get(qpayTokenCacheKey($merchant))['token'])->toBe('renewed-token');
});

test('a token about to expire is not kept', function () {
    $merchant = 'token-cache-merchant-' . uniqid();
    $history  = [];

    qpayTokenCacheClient($merchant, [
        new Response(200, [], json_encode(['access_token' => 'short-token', 'expires_in' => time() + 60])),
    ], $history)->setAuthToken();

    expect(Cache::get(qpayTokenCacheKey($merchant)))->toBeNull();
});
