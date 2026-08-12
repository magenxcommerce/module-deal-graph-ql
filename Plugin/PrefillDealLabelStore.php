<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Plugin;

use Magento\CatalogRule\Model\Rule\DataProvider;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Decodes the per-store-view deal-label overrides for the Catalog Price Rule
 * form.
 *
 * The overrides are stored as a JSON list of {store_id, label} in the
 * `magenx_deal_label_store` text column ({@see PersistDealFields}). The scalar
 * deal columns prefill straight from the rule collection, but the dynamicRows
 * field needs an array of records, so this decodes the JSON string back into
 * that shape on the form's data provider. Fails soft to an empty grid.
 */
class PrefillDealLabelStore
{
    private const FIELD = 'magenx_deal_label_store';

    /**
     * @param Json $serializer
     */
    public function __construct(
        private readonly Json $serializer
    ) {
    }

    /**
     * @param DataProvider $subject
     * @param mixed $result The data provider's per-rule data map (id => data).
     * @return mixed
     */
    public function afterGetData(DataProvider $subject, $result)
    {
        if (!is_array($result)) {
            return $result;
        }

        foreach ($result as $key => $ruleData) {
            if (is_array($ruleData) && array_key_exists(self::FIELD, $ruleData)) {
                $result[$key][self::FIELD] = $this->decode($ruleData[self::FIELD]);
            }
        }

        return $result;
    }

    /**
     * Normalize the stored JSON into a list of dynamicRows records. store_id is
     * a string so it binds to the select's option value.
     *
     * @param mixed $value
     * @return array<int, array{store_id: string, label: string}>
     */
    private function decode($value): array
    {
        if (is_string($value) && $value !== '') {
            try {
                $value = $this->serializer->unserialize($value);
            } catch (\InvalidArgumentException $e) {
                return [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row) && isset($row['store_id'], $row['label'])
                && is_scalar($row['store_id']) && is_scalar($row['label'])
            ) {
                $rows[] = [
                    'store_id' => (string) $row['store_id'],
                    'label' => (string) $row['label'],
                ];
            }
        }

        return $rows;
    }
}
