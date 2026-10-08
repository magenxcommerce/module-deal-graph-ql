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
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\Pricing\SaleableInterface;

/**
 * Resolves ProductInterface.deal — the labelled Catalog Price Rule matched to a
 * product for the request's website + customer group.
 *
 * A BATCH resolver on purpose: Magento hands it every product request of a
 * query branch (a grid, a related/upsell list) in one call, so all of their
 * ids are matched against `catalogrule_product` with ONE query through
 * {@see DealProvider::preloadMatches()}. As a plain resolver it cost one query
 * per product on every branch the `deals` query had not already warmed, e.g.
 * 24 queries for three linked-product lists of 8. Keep it a
 * BatchResolverInterface.
 *
 * Returns null when deals are disabled or the product matches no active deal
 * rule, so it never breaks a product query. `deal_price`/`regular_price` come
 * from the product's own rule-aware price info, so the badge price equals the
 * price Magento actually charges.
 */
class Deal implements BatchResolverInterface
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
     * @param ContextInterface $context
     * @param Field $field
     * @param BatchRequestItemInterface[] $requests
     * @return BatchResponse
     */
    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $response = new BatchResponse();

        $store = $context->getExtensionAttributes()?->getStore();
        $storeId = $store !== null ? (int) $store->getId() : null;

        if ($store === null || !$this->config->isEnabled($storeId)) {
            foreach ($requests as $request) {
                $response->addResponse($request, null);
            }

            return $response;
        }

        $websiteId = (int) $store->getWebsiteId();
        $groupId = $this->customerContext->getGroupId($context);

        $products = [];
        foreach ($requests as $key => $request) {
            $products[$key] = $this->productOf($request);
        }

        // One catalogrule_product query for the whole branch; each lookup
        // below is then served from the provider's request cache.
        $this->provider->preloadMatches(
            $websiteId,
            $groupId,
            array_map(
                static fn (ProductInterface $product): int => (int) $product->getId(),
                array_filter($products)
            )
        );

        try {
            $currency = $store->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            $currency = null;
        }

        foreach ($requests as $key => $request) {
            $product = $products[$key];
            $row = $product !== null
                ? $this->provider->matchProduct($websiteId, $groupId, (int) $product->getId())
                : null;

            if ($row === null) {
                $response->addResponse($request, null);
                continue;
            }

            $regularFallback = (float) $product->getPrice();
            $finalFallback = $product instanceof Product ? (float) $product->getFinalPrice() : $regularFallback;

            $response->addResponse($request, $this->provider->buildDealInfo(
                $row,
                $this->resolvePrice($product, RegularPrice::PRICE_CODE, $regularFallback),
                $this->resolvePrice($product, FinalPrice::PRICE_CODE, $finalFallback),
                $currency,
                $storeId
            ));
        }

        return $response;
    }

    /**
     * The product model carried on a batch request's parent value, or null.
     *
     * @param BatchRequestItemInterface $request
     * @return ProductInterface|null
     */
    private function productOf(BatchRequestItemInterface $request): ?ProductInterface
    {
        $value = $request->getValue();

        return isset($value['model']) && $value['model'] instanceof ProductInterface
            ? $value['model']
            : null;
    }

    /**
     * Read a named price off the product's (rule-aware) price info, falling back
     * to the raw attribute value when the pricing framework is unavailable — or
     * when the model is a bare ProductInterface implementation, which carries no
     * price info at all.
     *
     * @param ProductInterface $product
     * @param string $priceCode
     * @param float $fallback
     * @return float
     */
    private function resolvePrice(ProductInterface $product, string $priceCode, float $fallback): float
    {
        if (!$product instanceof SaleableInterface) {
            return $fallback;
        }

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
