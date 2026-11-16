<?php
declare(strict_types=1);

namespace JtlMedusa\Tests;

use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jtl\Connector\Core\Model\Identity;
use JtlMedusa\{CatalogWriter, MedusaClient, PrimaryKeyMapper};
use PHPUnit\Framework\TestCase;

final class CatalogWriterTest extends TestCase
{
    private string $lock;
    protected function setUp(): void { $this->lock = tempnam(sys_get_temp_dir(), 'jtl-lock-'); }
    protected function tearDown(): void { unlink($this->lock); }

    private function response(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function writer(array $responses, array &$history, PrimaryKeyMapper $mapper): CatalogWriter
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new CatalogWriter(new MedusaClient(new Client(['handler' => $stack, 'base_uri' => 'https://medusa.example.com/'])), $mapper, $this->lock);
    }

    public function testLostCreateResponseIsRecoveredByHandleAndUpdatesTheSameEntity(): void
    {
        $history = [];
        $mapper = new PrimaryKeyMapper(new \PDO('sqlite::memory:'));
        $existing = ['id' => 'pcat_1', 'metadata' => ['jtl_wawi_id' => '42', 'other_app' => 'keep']];
        $writer = $this->writer([
            $this->response(['product_categories' => [$existing]]),
            $this->response(['product_category' => $existing]),
        ], $history, $mapper);
        self::assertSame('pcat_1', $writer->upsert('product-categories', 1, new Identity('', 42), ['name' => 'Category', 'metadata' => ['jtl_wawi_id' => '42']]));
        self::assertSame('pcat_1', $mapper->getEndpointId(1, 42));
        self::assertStringContainsString('handle=jtl-42', (string) $history[0]['request']->getUri());
        self::assertSame('/admin/product-categories/pcat_1', $history[1]['request']->getUri()->getPath());
        $payload = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('keep', $payload['metadata']['other_app']);
    }

    public function testForeignCatalogEntitiesAreNeverClaimedByMatchingHandle(): void
    {
        $history = [];
        $writer = $this->writer([$this->response(['product_categories' => [['id' => 'foreign', 'metadata' => []]]])], $history, new PrimaryKeyMapper(new \PDO('sqlite::memory:')));
        try {
            $writer->upsert('product-categories', 1, new Identity('', 42), []);
            self::fail('Foreign category was overwritten.');
        } catch (\RuntimeException) { self::assertCount(1, $history); }
    }

    public function testFailedWritesNeverCommitAnIdentityMapping(): void
    {
        $history = [];
        $mapper = new PrimaryKeyMapper(new \PDO('sqlite::memory:'));
        $writer = $this->writer([$this->response(['product_categories' => []]), $this->response(['message' => 'sensitive body'], 503)], $history, $mapper);
        try {
            $writer->upsert('product-categories', 1, new Identity('', 42), []);
            self::fail('Failed write was accepted.');
        } catch (\RuntimeException $error) {
            self::assertNull($mapper->getEndpointId(1, 42));
            self::assertStringNotContainsString('sensitive body', $error->getMessage());
        }
    }

    public function testProductUpdatesKeepTheExistingVariantIdentity(): void
    {
        $history = [];
        $mapper = new PrimaryKeyMapper(new \PDO('sqlite::memory:'));
        $mapper->save(1, 'prod_1', 42);
        $existing = [
            'id' => 'prod_1', 'metadata' => ['jtl_wawi_id' => '42'],
            'variants' => [['id' => 'variant_1', 'metadata' => ['jtl_wawi_id' => '42', 'other_app' => 'keep']]],
        ];
        $writer = $this->writer([$this->response(['product' => $existing]), $this->response(['product' => $existing])], $history, $mapper);
        $writer->upsert('products', 1, new Identity('', 42), [
            'options' => [['title' => 'Default', 'values' => ['Default']]],
            'variants' => [['title' => 'Default', 'metadata' => ['jtl_wawi_id' => '42'], 'options' => ['Default' => 'Default']]],
        ]);
        $payload = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('variant_1', $payload['variants'][0]['id']);
        self::assertSame('keep', $payload['variants'][0]['metadata']['other_app']);
        self::assertArrayNotHasKey('options', $payload);
        self::assertArrayNotHasKey('options', $payload['variants'][0]);
    }

    public function testNewEntityCreationPersistsTheReturnedIdentity(): void
    {
        $history = [];
        $mapper = new PrimaryKeyMapper(new \PDO('sqlite::memory:'));
        $writer = $this->writer([$this->response(['product_categories' => []]), $this->response(['product_category' => ['id' => 'pcat_new']])], $history, $mapper);
        $writer->upsert('product-categories', 1, new Identity('', 42), ['name' => 'Category', 'metadata' => ['jtl_wawi_id' => '42']]);
        self::assertSame('pcat_new', $mapper->getEndpointId(1, 42));
        self::assertSame('/admin/product-categories', $history[1]['request']->getUri()->getPath());
        $payload = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('jtl-42', $payload['handle']);
    }
}
