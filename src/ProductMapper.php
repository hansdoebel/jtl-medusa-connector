<?php
declare(strict_types=1);

namespace JtlMedusa;

use Jtl\Connector\Core\Model\Product;

final class ProductMapper
{
    public function __construct(private Settings $settings) {}

    public function map(Product $product): array
    {
        if ($product->getId()->getHost() <= 0 || trim($product->getSku()) === '') {
            throw new \InvalidArgumentException('Simple products require a positive Wawi ID and a SKU.');
        }
        if ($product->isVariation() || $product->getMasterProductId()->getEndpoint() !== '' || $product->getVariations() !== [] || $product->getPartsLists() !== [] || $product->getSpecialPrices() !== []) {
            throw new \DomainException('Variants, bundles and special prices are not supported in the catalog milestone.');
        }
        $translation = null;
        foreach ($product->getI18ns() as $text) {
            if ($text->getLanguageIso() === $this->settings->language) { $translation = $text; break; }
        }
        if ($translation === null || trim($translation->getName()) === '') {
            throw new \DomainException('Missing product translation for the configured shop language.');
        }
        $amounts = [];
        foreach ($product->getPrices() as $price) {
            $group = $price->getCustomerGroupId();
            $customer = $price->getCustomerId();
            if ($customer->getHost() !== 0 || $customer->getEndpoint() !== '') {
                throw new \DomainException('Customer-specific prices are not supported yet.');
            }
            if ($group->getEndpoint() !== 'retail' && ($group->getEndpoint() !== '' || $group->getHost() !== 0)) {
                throw new \DomainException('Only the default retail customer group is supported yet.');
            }
            foreach ($price->getItems() as $item) {
                if ($item->getQuantity() > 1) { throw new \DomainException('Quantity price tiers are not supported yet.'); }
                $amounts[] = $item->getNetPrice();
            }
        }
        if (count($amounts) !== 1 || !is_finite($amounts[0]) || $amounts[0] < 0) {
            throw new \DomainException('Expected one nonnegative retail net price in the shop currency.');
        }
        $categories = [];
        foreach ($product->getCategories() as $category) {
            $id = $category->getCategoryId()->getEndpoint();
            if ($id === '') { throw new \DomainException('Synchronize product categories before products.'); }
            $categories[] = ['id' => $id];
        }
        $ownership = ['jtl_wawi_id' => (string) $product->getId()->getHost()];
        return [
            'title' => $translation->getName(),
            'description' => $translation->getDescription(),
            // Keep staging imports offline until stock, order export and tax settings are verified.
            'status' => 'draft',
            'shipping_profile_id' => $this->settings->shippingProfile,
            'metadata' => $ownership + ['jtl_is_active' => $product->getIsActive()],
            'categories' => $categories,
            'options' => [['title' => 'Default', 'values' => ['Default']]],
            'variants' => [[
                'title' => 'Default',
                'sku' => $product->getSku(),
                'ean' => $product->getEan() ?: null,
                'manage_inventory' => true,
                'allow_backorder' => false,
                'metadata' => $ownership,
                'options' => ['Default' => 'Default'],
                // Medusa v2 uses MAJOR units: 12.34 EUR remains 12.34, never 1234.
                // JTL supplies net prices; configure Medusa's region as tax exclusive.
                'prices' => [['currency_code' => $this->settings->currency, 'amount' => $amounts[0]]],
            ]],
        ];
    }
}
