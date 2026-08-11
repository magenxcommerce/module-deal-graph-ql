<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model;

use Magenx\DealGraphQl\Model\Config\Source\Type;
use Magento\Framework\App\ResourceConnection;

/**
 * Reads "deals" straight from Magento's native Catalog Price Rule index.
 *
 * A deal is any active Catalog Price Rule that carries a Magenx deal label (set
 * on the extended rule form). The rule already owns the product conditions, the
 * discount action, the schedule and the website/customer-group scope, and
 * Magento has already indexed which products it applies to into
 * `catalogrule_product`. So this provider does NOT re-implement rule matching or
 * pricing — it reads the index and reports the label/type/schedule; the actual
 * prices come from the product's own (rule-aware) price info in the resolver.
 *
 * Cheap on grids: the whole (productId → winning deal) map for a
 * website+customer-group is built with ONE indexed query per request and cached,
 * so a 24-card grid pays it once, not per product (unlike an EAV fan-out).
 */
class DealProvider
{
    /** @var array<string, array<int, array<string, mixed>>> productId => dealRow, keyed by "websiteId:groupId" */
    private array $indexCache = [];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * The active deal (labelled Catalog Price Rule) matched to a product, or null.
     *
     * @param int $websiteId
     * @param int $groupId
     * @param int $productId
     * @return array<string, mixed>|null
     */
    public function matchProduct(int $websiteId, int $groupId, int $productId): ?array
    {
        $index = $this->getDealIndex($websiteId, $groupId);

        return $index[$productId] ?? null;
    }

    /**
     * Ordered, de-duplicated product ids currently on a deal for this scope.
     *
     * @param int $websiteId
     * @param int $groupId
     * @param string|null $type Restrict to a single deal type, or null for all.
     * @param int $limit
     * @return int[]
     */
    public function getDealProductIds(int $websiteId, int $groupId, ?string $type, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $ids = [];
        foreach ($this->getDealIndex($websiteId, $groupId) as $productId => $row) {
            if ($type !== null && $row['type'] !== $type) {
                continue;
            }
            $ids[] = $productId;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Build the DealInfo payload for a matched rule.
     *
     * `deal_price` is the product's authoritative rule-aware final price and
     * `regular_price` its pre-rule price (both supplied by the resolver from the
     * product's price info), so what the storefront shows is what Magento
     * charges — no separate "advertised" figure. `percent_off` is derived from
     * those real prices; discount_type/value are the rule's action, informational.
     *
     * @param array<string, mixed> $row
     * @param float $regularPrice
     * @param float $finalPrice
     * @param string|null $currency
     * @param int|null $storeId Resolves the per-store-view label override.
     * @return array<string, mixed>
     */
    public function buildDealInfo(
        array $row,
        float $regularPrice,
        float $finalPrice,
        ?string $currency,
        ?int $storeId = null
    ): array {
        [$discountType, $discountValue] = $this->normalizeAction(
            (string) ($row['simple_action'] ?? ''),
            isset($row['discount_amount']) ? (float) $row['discount_amount'] : null
        );

        $regular = round($regularPrice, 2);
        $deal = round($finalPrice, 2);
        $percentOff = $regular > 0.0 ? round((1 - $deal / $regular) * 100, 2) : 0.0;

        $fromTime = (int) ($row['from_time'] ?? 0);
        $toTime = (int) ($row['to_time'] ?? 0);

        return [
            'label' => $this->resolveLabel($row, $storeId),
            'type' => self::toEnum((string) $row['type']),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'regular_price' => $regular,
            'deal_price' => $deal,
            'percent_off' => $percentOff,
            'currency' => $currency,
            'starts_at' => $fromTime > 0 ? gmdate('c', $fromTime) : null,
            'ends_at' => $toTime > 0 ? gmdate('c', $toTime) : null,
        ];
    }

    /**
     * The badge label for a store view: the per-store-view override if one is
     * set, otherwise the rule's default label.
     *
     * @param array<string, mixed> $row
     * @param int|null $storeId
     * @return string
     */
    public function resolveLabel(array $row, ?int $storeId): string
    {
        $default = (string) ($row['label'] ?? '');
        $overrides = (string) ($row['label_store'] ?? '');

        if ($storeId === null || $overrides === '') {
            return $default;
        }

        $decoded = json_decode($overrides, true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (is_array($entry)
                    && (int) ($entry['store_id'] ?? 0) === $storeId
                    && isset($entry['label'])
                    && is_string($entry['label'])
                    && $entry['label'] !== ''
                ) {
                    return $entry['label'];
                }
            }
        }

        return $default;
    }

    /**
     * Map an internal deal type to the GraphQL DealType enum value.
     *
     * @param string $type
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- pure enum mapping; nothing to intercept.
    public static function toEnum(string $type): string
    {
        return match ($type) {
            Type::TYPE_FIRST_ORDER => 'FIRST_ORDER',
            default => 'DEAL',
        };
    }

    /**
     * Map a GraphQL DealType enum value back to the internal deal type.
     *
     * @param string $enum
     * @return string|null
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- pure enum mapping; nothing to intercept.
    public static function fromEnum(string $enum): ?string
    {
        return match ($enum) {
            'DEAL' => Type::TYPE_DEAL,
            'FIRST_ORDER' => Type::TYPE_FIRST_ORDER,
            default => null,
        };
    }

    /**
     * Build (once per request) the productId => winning-deal map for a
     * website + customer group from the Catalog Price Rule index.
     *
     * @param int $websiteId
     * @param int $groupId
     * @return array<int, array<string, mixed>>
     */
    private function getDealIndex(int $websiteId, int $groupId): array
    {
        $key = $websiteId . ':' . $groupId;
        if (isset($this->indexCache[$key])) {
            return $this->indexCache[$key];
        }

        $index = [];
        try {
            $connection = $this->resource->getConnection();
            $now = time();

            $select = $connection->select()
                ->from(
                    ['crp' => $this->resource->getTableName('catalogrule_product')],
                    ['product_id' => 'crp.product_id', 'from_time' => 'crp.from_time', 'to_time' => 'crp.to_time']
                )
                ->join(
                    ['cr' => $this->resource->getTableName('catalogrule')],
                    'cr.rule_id = crp.rule_id',
                    [
                        'rule_id' => 'cr.rule_id',
                        'label' => 'cr.magenx_deal_label',
                        'label_store' => 'cr.magenx_deal_label_store',
                        'type' => 'cr.magenx_deal_type',
                        'simple_action' => 'cr.simple_action',
                        'discount_amount' => 'cr.discount_amount',
                    ]
                )
                ->where('cr.is_active = ?', 1)
                ->where('cr.magenx_deal_label IS NOT NULL')
                ->where('cr.magenx_deal_label <> ?', '')
                ->where('crp.website_id = ?', $websiteId)
                ->where('crp.customer_group_id = ?', $groupId)
                ->where('crp.from_time <= ?', $now)
                ->where('crp.to_time = 0 OR crp.to_time >= ?', $now)
                // Lowest rule sort_order (highest priority) wins per product.
                ->order(['crp.product_id ASC', 'crp.sort_order ASC']);

            foreach ($connection->fetchAll($select) as $row) {
                $productId = (int) $row['product_id'];
                if (isset($index[$productId])) {
                    continue;
                }
                $type = (string) ($row['type'] ?? '');
                $index[$productId] = [
                    'rule_id' => (int) $row['rule_id'],
                    'label' => (string) $row['label'],
                    'label_store' => (string) ($row['label_store'] ?? ''),
                    'type' => in_array($type, Type::TYPES, true) ? $type : Type::TYPE_DEAL,
                    'simple_action' => (string) ($row['simple_action'] ?? ''),
                    'discount_amount' => (float) ($row['discount_amount'] ?? 0),
                    'from_time' => (int) ($row['from_time'] ?? 0),
                    'to_time' => (int) ($row['to_time'] ?? 0),
                ];
            }
        } catch (\Exception $e) {
            // Columns not added yet / index empty — degrade to no deals rather
            // than letting a product query error.
            $index = [];
        }

        return $this->indexCache[$key] = $index;
    }

    /**
     * Normalize a Catalog Price Rule simple_action to a (discount_type, value)
     * pair for informational display. Percentage/fixed "off" actions map
     * directly; "to fixed/percent" actions have no single "off" figure, so they
     * report null and the storefront relies on the derived percent_off instead.
     *
     * @param string $simpleAction
     * @param float|null $amount
     * @return array{0: string|null, 1: float|null}
     */
    private function normalizeAction(string $simpleAction, ?float $amount): array
    {
        return match ($simpleAction) {
            'by_percent' => ['percent', $amount],
            'by_fixed' => ['fixed', $amount],
            default => [null, null],
        };
    }
}
