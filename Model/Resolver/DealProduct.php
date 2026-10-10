<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Resolver;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogGraphQl\Model\Resolver\Product\ProductFieldsSelector;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product as ProductDataProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\GraphQl\Model\Query\ContextInterface as QueryContextInterface;

/**
 * Resolves the `product` field on a DealItem.
 *
 * A BATCH resolver on purpose: every DealItem of the `deals` list is hydrated
 * by ONE product collection load that selects only the attributes the query
 * asked for — the same path core uses for related/upsell lists
 * (Magento_RelatedProductGraphQl's AbstractLikedProducts). Loading each item
 * through ProductRepositoryInterface::getById() instead cost a full EAV model
 * load (every attribute, media gallery, options, stock) per item, which was
 * most of a ~0.7 s GetDeals call. Keep it a BatchResolverInterface.
 *
 * The collection applies the store's catalog visibility (via the category
 * product index), so a product that is disabled, not visible in the catalog —
 * e.g. the simple child of a configurable the rule also matched — or removed
 * from the website resolves to null instead of rendering a card that links
 * nowhere. Each value carries the loaded model under `model`, the contract the
 * CatalogGraphQl ProductInterface field resolvers (and our own `deal`) read.
 */
class DealProduct implements BatchResolverInterface
{
    /**
     * @param ProductFieldsSelector $productFieldsSelector
     * @param ProductDataProvider $productDataProvider
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly ProductFieldsSelector $productFieldsSelector,
        private readonly ProductDataProvider $productDataProvider,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
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
        $productIds = [];
        $fields = [];
        foreach ($requests as $request) {
            $productId = (int) ($request->getValue()['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
            $fields[] = $this->productFieldsSelector->getProductFieldsFromInfo($request->getInfo());
        }

        $products = $productIds !== []
            ? $this->loadProducts(
                array_values($productIds),
                array_values(array_unique(array_merge([], ...$fields))),
                $context
            )
            : [];

        $response = new BatchResponse();
        foreach ($requests as $request) {
            $product = $products[(int) ($request->getValue()['product_id'] ?? 0)] ?? null;
            if ($product === null) {
                $response->addResponse($request, null);
                continue;
            }

            $productData = $product->getData();
            $productData['model'] = $product;
            $response->addResponse($request, $productData);
        }

        return $response;
    }

    /**
     * Load the requested products with only the selected attributes, keyed by id.
     *
     * @param int[] $productIds
     * @param string[] $attributes
     * @param ContextInterface $context
     * @return array<int, ProductInterface>
     */
    private function loadProducts(array $productIds, array $attributes, ContextInterface $context): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $productIds, 'in')
            ->create();

        $result = $this->productDataProvider->getList(
            $searchCriteria,
            $attributes,
            false,
            false,
            $context instanceof QueryContextInterface ? $context : null
        );

        $products = [];
        foreach ($result->getItems() as $product) {
            $products[(int) $product->getId()] = $product;
        }

        return $products;
    }
}
