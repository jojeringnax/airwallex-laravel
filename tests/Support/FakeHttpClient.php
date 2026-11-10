<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface|Throwable> $responses */
    public function __construct(public array $responses = []) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (! $response instanceof ResponseInterface) {
            throw new RuntimeException('Unexpected HTTP request in deterministic test.');
        }

        return $response;
    }

    public static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function login(?string $expiresAt = null, string $token = 'token-synthetic'): ResponseInterface
    {
        return self::json(['token' => $token, 'expires_at' => $expiresAt ?? gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
    }
}
