<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model;

use Magenx\DealGraphQl\Model\Config\Source\Type;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

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
 * Every read is bounded. The labelled rules themselves are loaded once per
 * request (a handful of rows from `catalogrule`); when a store has none — the
 * common case — no further query is issued at all and every deal lookup short
 * circuits to null. Product matching then hits `catalogrule_product` on its
 * `product_id` index for the ids actually being rendered, in batches, and the
 * result (including misses) is cached per request. That keeps memory flat on a
 * large catalog, where materialising the whole (product → rule) map would mean
 * pulling one row per product per rule into PHP on every product query.
 */
class DealProvider implements ResetAfterRequestInterface
{
    /** @var array<int, array<string, mixed>>|null ruleId => rule metadata, loaded once per request */
    private ?array $dealRules = null;

    /** @var array<string, array<int, array<string, mixed>|null>> "websiteId:groupId" => productId => deal row|null */
    private array $matchCache = [];

    /** @var int|null Stable "now" for the whole request, so a lookup can't straddle a schedule boundary. */
    private ?int $now = null;

    /**
     * @param ResourceConnection $resource
     * @param Json $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->dealRules = null;
        $this->matchCache = [];
        $this->now = null;
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
        if ($productId < 1 || $this->getDealRules() === []) {
            return null;
        }

        $key = $this->scopeKey($websiteId, $groupId);
        if (!array_key_exists($productId, $this->matchCache[$key] ?? [])) {
            $this->loadMatches($websiteId, $groupId, [$productId]);
        }

        return $this->matchCache[$key][$productId] ?? null;
    }

    /**
     * Ordered, de-duplicated product ids currently on a deal for this scope.
     *
     * The type filter is applied to the rules the products are matched against,
     * so `type: FIRST_ORDER` lists every product carrying a first-order deal.
     * The badge each product then renders is still the winning rule for that
     * product (see {@see matchProduct()}), which is the rule whose price Magento
     * actually charges.
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

        $rules = $this->getDealRules();
        if ($type !== null) {
            $rules = array_filter($rules, static fn (array $rule): bool => $rule['type'] === $type);
        }
        if ($rules === []) {
            return [];
        }

        try {
            $connection = $this->resource->getConnection();
            $select = $this->applyScope(
                $connection->select()
                    ->distinct(true)
                    ->from(['crp' => $this->resource->getTableName('catalogrule_product')], ['product_id']),
                $websiteId,
                $groupId
            )
                ->where('crp.rule_id IN (?)', array_keys($rules))
                ->order('crp.product_id ASC')
                ->limit($limit);

            $productIds = array_map('intval', $connection->fetchCol($select));
        } catch (\Exception $e) {
            $this->logDegraded($e);

            return [];
        }

        // Warm the match cache in one query so rendering each card's badge
        // afterwards costs nothing.
        $this->loadMatches($websiteId, $groupId, $productIds);

        return $productIds;
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
        $percentOff = $regular > 0.0 ? max(0.0, round((1 - $deal / $regular) * 100, 2)) : 0.0;

        $fromTime = (int) ($row['from_time'] ?? 0);
        $toTime = (int) ($row['to_time'] ?? 0);

        return [
            'label' => $this->resolveLabel($row, $storeId),
            'type' => self::toEnum((string) ($row['type'] ?? '')),
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

        try {
            $decoded = $this->serializer->unserialize($overrides);
        } catch (\InvalidArgumentException $e) {
            return $default;
        }

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
     * The active, labelled Catalog Price Rules, keyed by rule id. Loaded once per
     * request; an empty result means the store has no deals and every lookup can
     * short circuit without touching the (much larger) rule-product index.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDealRules(): array
    {
        if ($this->dealRules !== null) {
            return $this->dealRules;
        }

        $rules = [];
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(
                    ['cr' => $this->resource->getTableName('catalogrule')],
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
                ->where('cr.magenx_deal_label <> ?', '');

            foreach ($connection->fetchAll($select) as $row) {
                $type = (string) ($row['type'] ?? '');
                $rules[(int) $row['rule_id']] = [
                    'label' => (string) $row['label'],
                    'label_store' => (string) ($row['label_store'] ?? ''),
                    'type' => in_array($type, Type::TYPES, true) ? $type : Type::TYPE_DEAL,
                    'simple_action' => (string) ($row['simple_action'] ?? ''),
                    'discount_amount' => (float) ($row['discount_amount'] ?? 0),
                ];
            }
        } catch (\Exception $e) {
            // Columns not added yet / table unavailable — degrade to no deals
            // rather than letting a product query error.
            $this->logDegraded($e);
            $rules = [];
        }

        return $this->dealRules = $rules;
    }

    /**
     * Resolve the winning deal for a batch of product ids into the request cache.
     * Ids with no deal are cached as null so they are never looked up twice.
     *
     * @param int $websiteId
     * @param int $groupId
     * @param int[] $productIds
     * @return void
     */
    private function loadMatches(int $websiteId, int $groupId, array $productIds): void
    {
        $rules = $this->getDealRules();
        if ($rules === []) {
            return;
        }

        $key = $this->scopeKey($websiteId, $groupId);
        $this->matchCache[$key] ??= [];

        $pending = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId > 0 && !array_key_exists($productId, $this->matchCache[$key])) {
                // Negative-cache up front; the query below fills in the hits.
                $this->matchCache[$key][$productId] = null;
                $pending[] = $productId;
            }
        }
        if ($pending === []) {
            return;
        }

        try {
            $connection = $this->resource->getConnection();
            $select = $this->applyScope(
                $connection->select()->from(
                    ['crp' => $this->resource->getTableName('catalogrule_product')],
                    ['product_id', 'rule_id', 'from_time', 'to_time']
                ),
                $websiteId,
                $groupId
            )
                ->where('crp.rule_id IN (?)', array_keys($rules))
                ->where('crp.product_id IN (?)', $pending)
                // Lowest rule sort_order (highest priority) wins per product.
                ->order(['crp.product_id ASC', 'crp.sort_order ASC']);

            foreach ($connection->fetchAll($select) as $row) {
                $productId = (int) $row['product_id'];
                // First row per product wins; anything outside $pending is not ours.
                if (!array_key_exists($productId, $this->matchCache[$key])
                    || $this->matchCache[$key][$productId] !== null
                ) {
                    continue;
                }
                $rule = $rules[(int) $row['rule_id']] ?? null;
                if ($rule === null) {
                    continue;
                }
                $this->matchCache[$key][$productId] = $rule + [
                    'from_time' => (int) ($row['from_time'] ?? 0),
                    'to_time' => (int) ($row['to_time'] ?? 0),
                ];
            }
        } catch (\Exception $e) {
            $this->logDegraded($e);
        }
    }

    /**
     * Website + customer group + active-now scope, shared by both index reads.
     *
     * @param Select $select
     * @param int $websiteId
     * @param int $groupId
     * @return Select
     */
    private function applyScope(Select $select, int $websiteId, int $groupId): Select
    {
        $now = $this->now ??= time();

        return $select
            ->where('crp.website_id = ?', $websiteId)
            ->where('crp.customer_group_id = ?', $groupId)
            ->where('crp.from_time = 0 OR crp.from_time <= ?', $now)
            ->where('crp.to_time = 0 OR crp.to_time >= ?', $now);
    }

    /**
     * Cache key for a website + customer group scope.
     *
     * @param int $websiteId
     * @param int $groupId
     * @return string
     */
    private function scopeKey(int $websiteId, int $groupId): string
    {
        return $websiteId . ':' . $groupId;
    }

    /**
     * Record why deals degraded to "none". Silence here used to hide a missing
     * schema upgrade behind an empty storefront.
     *
     * @param \Exception $e
     * @return void
     */
    private function logDegraded(\Exception $e): void
    {
        $this->logger->warning(
            'Magenx_DealGraphQl: deal lookup unavailable, resolving to no deals. ' . $e->getMessage()
        );
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
