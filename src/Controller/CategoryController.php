<?php
declare(strict_types=1);

namespace JtlMedusa\Controller;

use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Definition\IdentityType;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Category;
use JtlMedusa\CatalogWriter;
use JtlMedusa\Settings;

final class CategoryController implements PushInterface
{
    public function __construct(private CatalogWriter $writer, private Settings $settings) {}

    public function push(AbstractModel ...$model): array
    {
        foreach ($model as $category) {
            if (!$category instanceof Category) { throw new \InvalidArgumentException('Expected a JTL Category.'); }
            $translation = null;
            foreach ($category->getI18ns() as $text) {
                if ($text->getLanguageIso() === $this->settings->language) { $translation = $text; break; }
            }
            if ($translation === null || trim($translation->getName()) === '') {
                throw new \DomainException('Missing category translation for the configured shop language.');
            }
            $parent = $category->getParentCategoryId();
            if ($parent->getHost() > 0 && $parent->getEndpoint() === '') {
                throw new \DomainException('Synchronize parent categories before children.');
            }
            $payload = [
                'name' => $translation->getName(),
                'description' => $translation->getDescription(),
                'is_active' => $category->getIsActive(),
                'is_internal' => true,
                'parent_category_id' => $parent->getEndpoint() ?: null,
                'metadata' => ['jtl_wawi_id' => (string) $category->getId()->getHost()],
            ];
            $id = $this->writer->upsert('product-categories', IdentityType::CATEGORY, $category->getId(), $payload);
            $category->getId()->setEndpoint($id);
        }
        return $model;
    }
}
