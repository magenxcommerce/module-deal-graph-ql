<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Gift;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\Rule;

/**
 * Reads gift deals: active Cart Price Rules that carry a Magenx gift SKU.
 *
 * Which rules apply to a given cart is decided by Magento's own rule engine
 * (the quote's applied_rule_ids after totals are collected), so this provider
 * only reports, per rule, what to add and how to label it. The gift rules are
 * loaded once per request — a handful of rows — and when a store has none,
 * every caller short-circuits on an empty array.
 */
class GiftRules implements ResetAfterRequestInterface
{
    /** Matches salesrule.magenx_gift_qty (smallint unsigned) and keeps a gift a gift. */
    public const MAX_QTY = 100;

    /** @var array<int, array{sku: string, qty: int}>|null ruleId => gift */
    private ?array $rules = null;

    /** @var array<string, array<int, string>> "storeId" => ruleId => label */
    private array $labels = [];

    /**
     * @param ResourceConnection $resource
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->rules = null;
        $this->labels = [];
    }

    /**
     * Every active rule with a gift SKU, keyed by rule id. Scope (website,
     * customer group), schedule and coupon are left to the rule engine.
     *
     * @return array<int, array{sku: string, qty: int}>
     */
    public function getActiveRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('salesrule'), ['rule_id', 'magenx_gift_sku', 'magenx_gift_qty'])
            ->where('is_active = ?', 1)
            ->where('magenx_gift_sku IS NOT NULL')
            ->where("magenx_gift_sku <> ''");

        $rules = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rules[(int) $row['rule_id']] = [
                'sku' => trim((string) $row['magenx_gift_sku']),
                'qty' => $this->normalizeQty($row['magenx_gift_qty']),
            ];
        }

        return $this->rules = $rules;
    }

    /**
     * Gift rules that apply without a coupon and are live now for this website
     * and customer group — what the storefront can advertise. Coupon rules are
     * left out on purpose: listing them would hint at codes meant to be shared
     * selectively.
     *
     * @param int $websiteId
     * @param int $groupId
     * @return array<int, array{sku: string, qty: int, ends_at: string|null}>
     */
    public function getAdvertisedRules(int $websiteId, int $groupId): array
    {
        $active = $this->getActiveRules();
        if ($active === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $today = $this->timezone->date()->format('Y-m-d');
        $select = $connection->select()
            ->from(['r' => $this->resource->getTableName('salesrule')], ['rule_id', 'to_date', 'sort_order'])
            ->join(['w' => $this->resource->getTableName('salesrule_website')], 'w.rule_id = r.rule_id', [])
            ->join(['g' => $this->resource->getTableName('salesrule_customer_group')], 'g.rule_id = r.rule_id', [])
            ->where('r.rule_id IN (?)', array_keys($active))
            ->where('r.coupon_type = ?', Rule::COUPON_TYPE_NO_COUPON)
            ->where('w.website_id = ?', $websiteId)
            ->where('g.customer_group_id = ?', $groupId)
            ->where('r.from_date IS NULL OR r.from_date <= ?', $today)
            ->where('r.to_date IS NULL OR r.to_date >= ?', $today)
            ->order(['r.sort_order ASC', 'r.rule_id ASC']);

        $rules = [];
        foreach ($connection->fetchAll($select) as $row) {
            $ruleId = (int) $row['rule_id'];
            $rules[$ruleId] = $active[$ruleId] + [
                'ends_at' => $row['to_date'] ? $row['to_date'] . 'T23:59:59' : null,
            ];
        }

        return $rules;
    }

    /**
     * The shopper-facing label of a gift rule: its store label for this store
     * view, else its default (store 0) label, else an empty string (the
     * storefront then shows its own generic "Free gift").
     *
     * @param int $ruleId
     * @param int $storeId
     * @return string
     */
    public function getLabel(int $ruleId, int $storeId): string
    {
        $key = (string) $storeId;
        if (!isset($this->labels[$key])) {
            $this->labels[$key] = $this->loadLabels($storeId);
        }

        return $this->labels[$key][$ruleId] ?? '';
    }

    /**
     * @param int $storeId
     * @return array<int, string>
     */
    private function loadLabels(int $storeId): array
    {
        $ruleIds = array_keys($this->getActiveRules());
        if ($ruleIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('salesrule_label'), ['rule_id', 'store_id', 'label'])
            ->where('rule_id IN (?)', $ruleIds)
            ->where('store_id IN (?)', array_unique([0, $storeId]));

        $labels = [];
        foreach ($connection->fetchAll($select) as $row) {
            $label = trim((string) $row['label']);
            if ($label === '') {
                continue;
            }
            $ruleId = (int) $row['rule_id'];
            // A store-view label beats the default one.
            if ((int) $row['store_id'] === $storeId || !isset($labels[$ruleId])) {
                $labels[$ruleId] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function normalizeQty(mixed $value): int
    {
        $qty = (int) $value;

        return $qty < 1 ? 1 : min($qty, self::MAX_QTY);
    }
}
