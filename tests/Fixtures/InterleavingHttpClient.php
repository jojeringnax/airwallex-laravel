<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests\Fixtures;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Invokes a bounded local interleaving instead of making network requests. */
final class InterleavingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses);
        if ($next instanceof Closure) {
            $next = $next();
        }
        if (! $next instanceof ResponseInterface) {
            throw new \RuntimeException('Unexpected deterministic HTTP request.');
        }

        return $next;
    }
}
