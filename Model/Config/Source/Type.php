<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Deal-type options for the deal-campaign admin grid.
 *
 * The stored value (deal|first_order) maps to the GraphQL DealType enum via
 * {@see \Magenx\DealGraphQl\Model\DealProvider::toEnum()}. "Deal" is the generic
 * type — its free-text label, conditions and schedule live on the rule, so it
 * covers flash sales / special offers without a dedicated type; "First Order"
 * is distinct because it drives the customer-group automation.
 */
class Type implements OptionSourceInterface
{
    public const TYPE_DEAL = 'deal';
    public const TYPE_FIRST_ORDER = 'first_order';

    public const TYPES = [
        self::TYPE_DEAL,
        self::TYPE_FIRST_ORDER,
    ];

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::TYPE_DEAL, 'label' => __('Deal')],
            ['value' => self::TYPE_FIRST_ORDER, 'label' => __('First Order')],
        ];
    }
}
