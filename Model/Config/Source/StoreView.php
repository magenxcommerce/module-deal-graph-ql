<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store-view options for the per-store-view deal-label override grid on the
 * Catalog Price Rule form.
 *
 * Lists every non-admin store view as "Website / Store — view (code)" so a
 * merchant can pick which language/store view a label override applies to. The
 * override map is keyed by the numeric store id (see
 * {@see \Magenx\DealGraphQl\Model\DealProvider::resolveLabel()}).
 */
class StoreView implements OptionSourceInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores() as $store) {
            $options[] = [
                'value' => (string) $store->getId(),
                'label' => $store->getName() . ' (' . $store->getCode() . ')',
            ];
        }

        return $options;
    }
}
