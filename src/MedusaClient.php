<?php
declare(strict_types=1);

namespace JtlMedusa;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class MedusaClient
{
    public function __construct(private ClientInterface $http) {}

    public function request(string $method, string $path, array $data = []): array
    {
        try {
            $options = ['http_errors' => false, 'allow_redirects' => false];
            if ($data !== []) { $options[$method === 'GET' ? 'query' : 'json'] = $data; }
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException) {
            // Never expose headers, credentials, URLs with query values or customer data in RPC errors.
            throw new \RuntimeException('Medusa connection failed; retry synchronization after checking connectivity.');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('Medusa rejected the request (HTTP ' . $response->getStatusCode() . ').');
        }
        $result = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new \RuntimeException('Invalid Medusa response.'); }
        return $result;
    }
}
