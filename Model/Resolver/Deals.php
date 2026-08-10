<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Resolver;

use Magenx\DealGraphQl\Model\Config;
use Magenx\DealGraphQl\Model\CustomerContext;
use Magenx\DealGraphQl\Model\DealProvider;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves the `deals` query — products currently on a labelled Catalog Price
 * Rule for the request's website + customer group.
 *
 * The `product` sub-field is resolved lazily by {@see DealProduct} (and its
 * `deal` by {@see Product\Deal}), so a grid only pays for the fields it asks
 * for. Returns an empty list when the feature is disabled.
 */
class Deals implements ResolverInterface
{
    /**
     * @param Config $config
     * @param DealProvider $provider
     * @param CustomerContext $customerContext
     */
    public function __construct(
        private readonly Config $config,
        private readonly DealProvider $provider,
        private readonly CustomerContext $customerContext
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int) $store->getId();

        if (!$this->config->isEnabled($storeId)) {
            return ['items' => [], 'total_count' => 0];
        }

        $max = $this->config->getCount($storeId);
        $requested = isset($args['pageSize']) ? (int) $args['pageSize'] : $max;
        $limit = max(1, min($requested, $max));

        $type = isset($args['type']) ? DealProvider::fromEnum((string) $args['type']) : null;

        $productIds = $this->provider->getDealProductIds(
            (int) $store->getWebsiteId(),
            $this->customerContext->getGroupId($context),
            $type,
            $limit
        );

        $items = [];
        foreach ($productIds as $productId) {
            $items[] = [
                // Consumed by the DealProduct field resolver.
                'product_id' => $productId,
                'store_id' => $storeId,
            ];
        }

        return [
            'items' => $items,
            'total_count' => count($items),
        ];
    }
}
