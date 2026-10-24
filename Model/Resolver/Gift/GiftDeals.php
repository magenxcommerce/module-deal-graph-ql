<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Resolver\Gift;

use Magenx\DealGraphQl\Model\Config;
use Magenx\DealGraphQl\Model\CustomerContext;
use Magenx\DealGraphQl\Model\Gift\GiftRules;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves the `giftDeals` query: gift rules live now, without a coupon, for the
 * request's website and customer group — what the storefront can advertise on
 * the deals page. The `product` of each is hydrated in one batch by
 * {@see \Magenx\DealGraphQl\Model\Resolver\DealProduct}, which also hides a gift
 * product that is disabled or not visible in the catalog.
 */
class GiftDeals implements ResolverInterface
{
    /**
     * @param Config $config
     * @param GiftRules $giftRules
     * @param CustomerContext $customerContext
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly Config $config,
        private readonly GiftRules $giftRules,
        private readonly CustomerContext $customerContext,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int) $store->getId();
        if (!$this->config->isEnabled($storeId) || !$this->config->isGiftEnabled($storeId)) {
            return [];
        }

        $rules = $this->giftRules->getAdvertisedRules(
            (int) $store->getWebsiteId(),
            $this->customerContext->getGroupId($context)
        );
        if ($rules === []) {
            return [];
        }

        $productIds = $this->productIdsBySku(array_unique(array_column($rules, 'sku')));

        $items = [];
        foreach ($rules as $ruleId => $rule) {
            $productId = $productIds[$rule['sku']] ?? null;
            if ($productId === null) {
                continue;
            }
            $label = $this->giftRules->getLabel($ruleId, $storeId);
            $items[] = [
                'rule_id' => $ruleId,
                'label' => $label !== '' ? $label : null,
                'gift_qty' => $rule['qty'],
                'ends_at' => $rule['ends_at'],
                // Consumed by the DealProduct batch resolver.
                'product_id' => $productId,
            ];
        }

        return $items;
    }

    /**
     * @param string[] $skus
     * @return array<string, int>
     */
    private function productIdsBySku(array $skus): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), ['sku', 'entity_id'])
            ->where('sku IN (?)', $skus);

        $ids = [];
        foreach ($connection->fetchPairs($select) as $sku => $id) {
            $ids[(string) $sku] = (int) $id;
        }

        return $ids;
    }
}
