<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Gift;

use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Recognises gift lines: quote items carrying the {@see OPTION_CODE} option
 * (the id of the gift rule) added by {@see GiftSync}.
 */
class GiftLine
{
    public const OPTION_CODE = 'magenx_deal_gift_rule';

    /**
     * The rule id a quote item is the gift of, or null for an ordinary line.
     *
     * @param AbstractItem $item
     * @return int|null
     */
    public function getRuleId(AbstractItem $item): ?int
    {
        $option = $item->getOptionByCode(self::OPTION_CODE);
        $ruleId = $option ? (int) $option->getValue() : 0;

        return $ruleId > 0 ? $ruleId : null;
    }
}
