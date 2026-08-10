<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Plugin;

use Magento\CatalogRule\Model\Rule;

/**
 * Persists the Magenx deal fields (default label, per-store label overrides,
 * type) posted by the extended Catalog Price Rule admin form onto the rule model.
 *
 * The Save controller calls Rule::loadPost() with the full POST array before
 * save(); the core method only maps conditions/actions, so we copy our own
 * fields across here. The per-store overrides arrive as a dynamicRows array and
 * are stored as a JSON list of {store_id, label} in a text column (decoded back
 * for the form by {@see PrefillDealLabelStore}). The scalar columns load
 * automatically on read; the JSON column is decoded by that prefill plugin.
 */
class PersistDealFields
{
    /** Scalar fields copied straight across (trimmed). */
    private const SCALAR_FIELDS = ['magenx_deal_label', 'magenx_deal_type'];

    /** The dynamicRows per-store-view label overrides, stored as JSON. */
    private const STORE_LABELS_FIELD = 'magenx_deal_label_store';

    /**
     * @param Rule $subject
     * @param Rule $result The rule returned by loadPost() ($this).
     * @param array $data The raw POST data passed to loadPost().
     * @return Rule
     */
    public function afterLoadPost(Rule $subject, Rule $result, array $data): Rule
    {
        foreach (self::SCALAR_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $result->setData($field, is_string($value) ? trim($value) : $value);
            }
        }

        if (array_key_exists(self::STORE_LABELS_FIELD, $data)) {
            $result->setData(self::STORE_LABELS_FIELD, $this->encodeStoreLabels($data[self::STORE_LABELS_FIELD]));
        }

        return $result;
    }

    /**
     * Encode the dynamicRows per-store overrides into a JSON list, dropping
     * incomplete rows and duplicate store views (first row wins). Returns null
     * when there are no valid overrides so the column clears.
     *
     * @param mixed $value
     * @return string|null
     */
    private function encodeStoreLabels($value): ?string
    {
        // Already a serialized string (unexpected path) — keep or clear.
        if (!is_array($value)) {
            return is_string($value) && $value !== '' ? $value : null;
        }

        $rows = [];
        $seen = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $storeId = isset($row['store_id']) ? (int) $row['store_id'] : 0;
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';
            if ($storeId <= 0 || $label === '' || isset($seen[$storeId])) {
                continue;
            }
            $seen[$storeId] = true;
            $rows[] = ['store_id' => $storeId, 'label' => $label];
        }

        return $rows === [] ? null : json_encode($rows);
    }
}
