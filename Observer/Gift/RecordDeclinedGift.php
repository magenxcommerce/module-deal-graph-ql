<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Observer\Gift;

use Magenx\DealGraphQl\Model\Gift\GiftLine;
use Magenx\DealGraphQl\Model\Gift\GiftSync;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Remembers that the shopper removed a gift (event: sales_quote_remove_item),
 * so {@see GiftSync} does not put it straight back. Removals {@see GiftSync}
 * makes itself (the rule stopped applying) are flagged and ignored.
 *
 * The record lives on the quote, so it lasts as long as that cart.
 */
class RecordDeclinedGift implements ObserverInterface
{
    /** Matches quote.magenx_declined_gift_rules (varchar 255). */
    private const MAX_LENGTH = 255;

    /**
     * @param GiftLine $giftLine
     */
    public function __construct(
        private readonly GiftLine $giftLine
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getData('quote_item');
        if (!$item instanceof AbstractItem || $item->getData(GiftSync::AUTO_REMOVED_FLAG)) {
            return;
        }

        $ruleId = $this->giftLine->getRuleId($item);
        $quote = $item->getQuote();
        if ($ruleId === null || $quote === null) {
            return;
        }

        $ids = array_filter(array_map('intval', explode(',', (string) $quote->getData(GiftSync::DECLINED_FIELD))));
        $ids[] = $ruleId;
        $value = implode(',', array_unique($ids));
        // Oldest first out if a cart ever declines more gifts than fit.
        while (strlen($value) > self::MAX_LENGTH && str_contains($value, ',')) {
            $value = substr($value, strpos($value, ',') + 1);
        }

        $quote->setData(GiftSync::DECLINED_FIELD, $value);
    }
}
