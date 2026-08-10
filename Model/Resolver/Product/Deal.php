<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Resolver\Product;

use Magenx\DealGraphQl\Model\Config;
use Magenx\DealGraphQl\Model\CustomerContext;
use Magenx\DealGraphQl\Model\DealProvider;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves ProductInterface.deal — the labelled Catalog Price Rule matched to a
 * product for the request's website + customer group.
 *
 * Returns null when deals are disabled or the product matches no active deal
 * rule, so it never breaks a product query. The match is an O(1) lookup into a
 * per-request index built with one query in {@see DealProvider}, so it is safe
 * on listing grids — the "DEAL" badge must render on category/search cards.
 * `deal_price`/`regular_price` come from the product's own rule-aware price info,
 * so the badge price equals the price Magento actually charges.
 */
class Deal implements ResolverInterface
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
        if (!isset($value['model']) || !$value['model'] instanceof ProductInterface) {
            throw new GraphQlInputException(__('"model" value should be specified.'));
        }

        $store = $context->getExtensionAttributes()->getStore();
        $storeId = (int) $store->getId();

        if (!$this->config->isEnabled($storeId)) {
            return null;
        }

        /** @var ProductInterface $product */
        $product = $value['model'];

        $row = $this->provider->matchProduct(
            (int) $store->getWebsiteId(),
            $this->customerContext->getGroupId($context),
            (int) $product->getId()
        );

        if ($row === null) {
            return null;
        }

        $currency = null;
        try {
            $currency = $store->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            $currency = null;
        }

        return $this->provider->buildDealInfo(
            $row,
            $this->resolvePrice($product, RegularPrice::PRICE_CODE, (float) $product->getPrice()),
            $this->resolvePrice($product, FinalPrice::PRICE_CODE, (float) $product->getFinalPrice()),
            $currency,
            $storeId
        );
    }

    /**
     * Read a named price off the product's (rule-aware) price info, falling back
     * to the raw attribute value when the pricing framework is unavailable.
     *
     * @param ProductInterface $product
     * @param string $priceCode
     * @param float $fallback
     * @return float
     */
    private function resolvePrice(ProductInterface $product, string $priceCode, float $fallback): float
    {
        try {
            $amount = $product->getPriceInfo()->getPrice($priceCode)->getAmount()->getValue();
            if ($amount !== null) {
                return (float) $amount;
            }
        } catch (\Exception $e) {
            // Fall through to the raw attribute value.
        }

        return $fallback;
    }
}
