<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Plugin;

use Magenx\DealGraphQl\Model\Config\Source\Type;
use Magento\CatalogRule\Model\Rule;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

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
 *
 * Everything is normalized on the way in rather than trusted: the label is
 * trimmed and clipped to the column width, the type is accepted only if it is a
 * known {@see Type} value, and duplicate/incomplete override rows are dropped.
 * An out-of-range value would otherwise reach MySQL and fail the rule save
 * outright under strict mode.
 */
class PersistDealFields
{
    private const LABEL_FIELD = 'magenx_deal_label';
    private const TYPE_FIELD = 'magenx_deal_type';

    /** The dynamicRows per-store-view label overrides, stored as JSON. */
    private const STORE_LABELS_FIELD = 'magenx_deal_label_store';

    /** Matches the varchar length of `catalogrule.magenx_deal_label` (db_schema.xml). */
    private const MAX_LABEL_LENGTH = 255;

    /**
     * @param Json $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Json $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Rule $subject
     * @param Rule $result The rule returned by loadPost() ($this).
     * @param array $data The raw POST data passed to loadPost().
     * @return Rule
     */
    public function afterLoadPost(Rule $subject, Rule $result, array $data): Rule
    {
        if (array_key_exists(self::LABEL_FIELD, $data)) {
            $result->setData(self::LABEL_FIELD, $this->normalizeLabel($data[self::LABEL_FIELD]));
        }

        if (array_key_exists(self::TYPE_FIELD, $data)) {
            $result->setData(self::TYPE_FIELD, $this->normalizeType($data[self::TYPE_FIELD]));
        }

        if (array_key_exists(self::STORE_LABELS_FIELD, $data)) {
            $result->setData(self::STORE_LABELS_FIELD, $this->encodeStoreLabels($data[self::STORE_LABELS_FIELD]));
        }

        return $result;
    }

    /**
     * Trim a posted badge label and clip it to the column width. Returns null for
     * anything empty or non-scalar, which is what "not a deal" means on read.
     *
     * @param mixed $value
     * @return string|null
     */
    private function normalizeLabel($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $label = trim((string) $value);

        return $label === '' ? null : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }

    /**
     * Accept only a known deal type; anything else clears the column (and reads
     * back as the generic DEAL type).
     *
     * @param mixed $value
     * @return string|null
     */
    private function normalizeType($value): ?string
    {
        $type = is_scalar($value) ? trim((string) $value) : '';

        return in_array($type, Type::TYPES, true) ? $type : null;
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
        $value = $this->toRows($value);

        $rows = [];
        $seen = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $storeId = isset($row['store_id']) && is_scalar($row['store_id']) ? (int) $row['store_id'] : 0;
            $label = isset($row['label']) && is_scalar($row['label']) ? trim((string) $row['label']) : '';
            if ($storeId <= 0 || $label === '' || isset($seen[$storeId])) {
                continue;
            }
            $seen[$storeId] = true;
            $rows[] = ['store_id' => $storeId, 'label' => mb_substr($label, 0, self::MAX_LABEL_LENGTH)];
        }

        if ($rows === []) {
            return null;
        }

        try {
            return $this->serializer->serialize($rows);
        } catch (\InvalidArgumentException $e) {
            // Un-encodable input (e.g. invalid UTF-8) must not 500 the rule save.
            $this->logger->error(
                'Magenx_DealGraphQl: could not encode per-store deal labels, clearing them. ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Normalize the posted overrides to a list of rows. The form posts an array;
     * an already-serialized string (unexpected path) is decoded so it is
     * re-validated rather than written through unchecked.
     *
     * @param mixed $value
     * @return array<int|string, mixed>
     */
    private function toRows($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
