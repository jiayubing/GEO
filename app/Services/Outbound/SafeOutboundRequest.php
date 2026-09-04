<?php

namespace App\Services\Outbound;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class SafeOutboundRequest
{
    public function __construct(
        private readonly SafeOutboundHttpClient $client,
        private PendingRequest $request,
        private readonly int $maxResponseBytes,
        private readonly int $maxRedirects = 0,
    ) {}

    /** @param array<string, mixed> $headers */
    public function withHeaders(array $headers): self
    {
        $this->request = $this->request->withHeaders($headers);

        return $this;
    }

    public function withBody(string $content, string $contentType = 'application/json'): self
    {
        $this->request = $this->request->withBody($content, $contentType);

        return $this;
    }

    /** @param resource|string $contents */
    public function attach(string $name, mixed $contents, ?string $filename = null, array $headers = []): self
    {
        $this->request = $this->request->attach($name, $contents, $filename, $headers);

        return $this;
    }

    public function asMultipart(): self
    {
        $this->request = $this->request->asMultipart();

        return $this;
    }

    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = []): Response
    {
        return $this->client->get($this->request, $url, $this->maxResponseBytes, $this->maxRedirects, $query);
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = []): Response
    {
        return $this->client->send($this->request, 'POST', $url, $data, $this->maxResponseBytes, $this->maxRedirects);
    }

    /** @param array<string, mixed> $data */
    public function delete(string $url, array $data = []): Response
    {
        return $this->client->delete($this->request, $url, $data, $this->maxResponseBytes);
    }

    /** @param array<string, mixed> $data */
    public function send(string $method, string $url, array $data = []): Response
    {
        return $this->client->send($this->request, $method, $url, $data, $this->maxResponseBytes, $this->maxRedirects);
    }
}
