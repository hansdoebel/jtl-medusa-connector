<?php
declare(strict_types=1);

namespace JtlMedusa;

use Jtl\Connector\Core\Model\Identity;

final class CatalogWriter
{
    public function __construct(private MedusaClient $medusa, private PrimaryKeyMapper $mapper, private string $lockFile) {}

    public function upsert(string $kind, int $type, Identity $identity, array $payload): string
    {
        if ($identity->getHost() <= 0 || !in_array($kind, ['products', 'product-categories'], true)) {
            throw new \InvalidArgumentException('A catalog push requires a positive Wawi ID and supported resource.');
        }
        $lock = fopen($this->lockFile, 'c');
        if ($lock === false) { throw new \RuntimeException('Cannot open catalog synchronization lock.'); }
        try {
            if (!flock($lock, LOCK_EX)) { throw new \RuntimeException('Cannot lock catalog synchronization.'); }
            $host = $identity->getHost();
            $handle = 'jtl-' . $host;
            $single = $kind === 'products' ? 'product' : 'product_category';
            $plural = $kind === 'products' ? 'products' : 'product_categories';
            $mapped = $this->mapper->getEndpointId($type, $host);
            if ($mapped !== null && $identity->getEndpoint() !== '' && $identity->getEndpoint() !== $mapped) {
                throw new \RuntimeException('Incoming endpoint ID conflicts with the persisted mapping.');
            }
            $endpoint = $mapped ?? ($identity->getEndpoint() ?: null);
            $existing = null;
            if ($endpoint !== null) {
                $existing = $this->medusa->request('GET', "admin/$kind/" . rawurlencode($endpoint))[$single] ?? null;
                if (!is_array($existing)) { throw new \RuntimeException('Mapped Medusa entity is missing.'); }
            } else {
                // Recover a successful remote create when the response or local commit was lost.
                $matches = $this->medusa->request('GET', "admin/$kind", ['handle' => $handle, 'limit' => 2])[$plural] ?? null;
                if (!is_array($matches) || count($matches) > 1) { throw new \RuntimeException('Ambiguous Medusa handle lookup.'); }
                $existing = $matches[0] ?? null;
            }
            if ($existing !== null) {
                if ((string) ($existing['metadata']['jtl_wawi_id'] ?? '') !== (string) $host) {
                    throw new \RuntimeException('Refusing to overwrite a Medusa entity without matching JTL ownership.');
                }
                $endpoint = $existing['id'];
                $payload['metadata'] = array_replace($existing['metadata'] ?? [], $payload['metadata'] ?? []);
                if ($kind === 'products') {
                    $variants = $existing['variants'] ?? [];
                    if (count($variants) !== 1 || (string) ($variants[0]['metadata']['jtl_wawi_id'] ?? '') !== (string) $host) {
                        throw new \RuntimeException('Expected exactly one JTL-owned simple product variant.');
                    }
                    $payload['variants'][0]['id'] = $variants[0]['id'];
                    $payload['variants'][0]['metadata'] = array_replace($variants[0]['metadata'] ?? [], $payload['variants'][0]['metadata']);
                    // Existing option IDs/values remain associated with the existing variant.
                    unset($payload['options'], $payload['variants'][0]['options']);
                }
            } else {
                $payload['handle'] = $handle;
            }
            $path = "admin/$kind" . ($endpoint === null ? '' : '/' . rawurlencode($endpoint));
            $result = $this->medusa->request('POST', $path, $payload)[$single] ?? null;
            if (!is_array($result) || empty($result['id']) || ($endpoint !== null && $result['id'] !== $endpoint)) {
                throw new \RuntimeException('Medusa returned an invalid catalog identity.');
            }
            $this->mapper->save($type, $result['id'], $host);
            return $result['id'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
