<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Plugin\Gift;

use Magenx\DealGraphQl\Model\Gift\GiftRules;
use Magento\SalesRule\Model\Rule;

/**
 * Persists the gift fields posted by the extended Cart Price Rule form.
 *
 * The Save controller calls Rule::loadPost() with the full POST before save();
 * the core method only maps conditions and actions, so the gift columns are
 * copied across here (the same pattern as {@see \Magenx\DealGraphQl\Plugin\PersistDealFields}
 * for Catalog Price Rules). The values are normalized rather than trusted: the
 * SKU is trimmed and clipped to the column width, the quantity is clamped, and
 * an empty SKU clears both — which is what "not a gift deal" means.
 */
class PersistGiftFields
{
    private const SKU_FIELD = 'magenx_gift_sku';
    private const QTY_FIELD = 'magenx_gift_qty';

    /** Matches the varchar length of `salesrule.magenx_gift_sku` (db_schema.xml). */
    private const MAX_SKU_LENGTH = 64;

    /**
     * @param Rule $subject
     * @param Rule $result
     * @param array $data
     * @return Rule
     */
    public function afterLoadPost(Rule $subject, Rule $result, array $data): Rule
    {
        if (!array_key_exists(self::SKU_FIELD, $data)) {
            return $result;
        }

        $sku = is_scalar($data[self::SKU_FIELD]) ? trim((string) $data[self::SKU_FIELD]) : '';
        if ($sku === '') {
            $result->setData(self::SKU_FIELD, null);
            $result->setData(self::QTY_FIELD, null);
            return $result;
        }

        $qty = isset($data[self::QTY_FIELD]) && is_scalar($data[self::QTY_FIELD]) ? (int) $data[self::QTY_FIELD] : 1;
        $result->setData(self::SKU_FIELD, mb_substr($sku, 0, self::MAX_SKU_LENGTH));
        $result->setData(self::QTY_FIELD, max(1, min($qty, GiftRules::MAX_QTY)));

        return $result;
    }
}
