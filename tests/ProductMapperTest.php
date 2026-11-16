<?php
declare(strict_types=1);

namespace JtlMedusa\Tests;

use Jtl\Connector\Core\Model\{Identity, Product, ProductI18n, ProductPrice, ProductPriceItem};
use JtlMedusa\{ProductMapper, Settings};
use PHPUnit\Framework\TestCase;

final class ProductMapperTest extends TestCase
{
    private function mapper(): ProductMapper
    {
        return new ProductMapper(new Settings(str_repeat('x', 32), 'https://medusa.example.com', 'test-key', 'sp_1'));
    }

    private function product(): Product
    {
        return (new Product())->setId(new Identity('', 42))->setSku('SKU-42')
            ->addI18n((new ProductI18n())->setLanguageIso('de')->setName('Test product'))
            ->addPrice((new ProductPrice())->setCustomerGroupId(new Identity('retail', 1))
                ->addItem((new ProductPriceItem())->setQuantity(1)->setNetPrice(12.34)));
    }

    public function testV2PricesStayInMajorUnitsAndImportsRemainDrafts(): void
    {
        $payload = $this->mapper()->map($this->product());
        self::assertSame(12.34, $payload['variants'][0]['prices'][0]['amount']);
        self::assertSame('eur', $payload['variants'][0]['prices'][0]['currency_code']);
        self::assertSame('42', $payload['metadata']['jtl_wawi_id']);
        self::assertSame('draft', $payload['status']);
        self::assertTrue($payload['variants'][0]['manage_inventory']);
        self::assertFalse($payload['variants'][0]['allow_backorder']);
    }

    public function testVariantsAreRejectedRatherThanFlattened(): void
    {
        $product = $this->product()->setIsMasterProduct(true);
        $this->expectException(\DomainException::class);
        $this->mapper()->map($product);
    }

    public function testMissingPriceCannotSilentlyCreateAFreeProduct(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper()->map($this->product()->clearPrices());
    }

    public function testQuantityTiersCannotSilentlyBecomeBasePrices(): void
    {
        $product = $this->product();
        $product->getPrices()[0]->addItem((new ProductPriceItem())->setQuantity(10)->setNetPrice(10.0));
        $this->expectException(\DomainException::class);
        $this->mapper()->map($product);
    }
}
