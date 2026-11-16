<?php
declare(strict_types=1);

namespace JtlMedusa\Controller;

use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Definition\IdentityType;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;
use JtlMedusa\CatalogWriter;
use JtlMedusa\ProductMapper;

final class ProductController implements PushInterface
{
    public function __construct(private CatalogWriter $writer, private ProductMapper $mapper) {}

    public function push(AbstractModel ...$model): array
    {
        foreach ($model as $product) {
            if (!$product instanceof Product) { throw new \InvalidArgumentException('Expected a JTL Product.'); }
            $id = $this->writer->upsert('products', IdentityType::PRODUCT, $product->getId(), $this->mapper->map($product));
            $product->getId()->setEndpoint($id);
        }
        return $model;
    }
}
