<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Plugin\Gift;

use Magenx\DealGraphQl\Model\Gift\GiftSync;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Syncs gift lines just before a cart is saved.
 *
 * Every cart change funnels through CartRepositoryInterface::save — adding,
 * updating and removing items, applying or removing a coupon, setting an
 * address — over GraphQL, REST and Luma alike, so this one seam keeps the gift
 * in step with whatever changed the rule's outcome, and the gift line is saved
 * in the same write. {@see GiftSync} returns immediately when gift deals are off
 * or no gift rule exists, so a store without gift deals pays nothing.
 *
 * A gift failure must never fail the cart: it is logged and the save goes on.
 */
class SyncGiftsOnCartSave
{
    private bool $running = false;

    /**
     * @param GiftSync $giftSync
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GiftSync $giftSync,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param CartRepositoryInterface $subject
     * @param CartInterface $quote
     * @return array{0: CartInterface}
     */
    public function beforeSave(CartRepositoryInterface $subject, CartInterface $quote): array
    {
        if ($this->running || !$quote instanceof Quote) {
            return [$quote];
        }

        $this->running = true;
        try {
            $this->giftSync->sync($quote);
        } catch (\Throwable $e) {
            $this->logger->error('Magenx_DealGraphQl: gift deal sync failed: ' . $e->getMessage(), ['exception' => $e]);
        } finally {
            $this->running = false;
        }

        return [$quote];
    }
}
