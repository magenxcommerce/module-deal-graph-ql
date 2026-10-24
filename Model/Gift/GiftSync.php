<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Gift;

use Magenx\DealGraphQl\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;

/**
 * Brings a cart's gift lines in line with the gift rules that apply to it.
 *
 * Runs before a cart is saved ({@see \Magenx\DealGraphQl\Plugin\Gift\SyncGiftsOnCartSave}):
 *
 *  - a gift rule in the quote's applied_rule_ids whose gift is not in the cart
 *    (and that the shopper has not removed from this cart) → add the gift;
 *  - a gift line whose rule no longer applies, is no longer a gift rule, or with
 *    the feature switched off → remove it;
 *  - a gift line whose quantity was changed → put it back to the rule's quantity.
 *
 * A gift line is an ordinary quote item carrying the {@see GiftLine::OPTION_CODE} option
 * (the rule id) with a custom price of 0, so it is its own line even when the
 * same product is also bought, survives to the order as a 0-priced line, and is
 * never discounted below 0. Which rules apply is decided by Magento's rule
 * engine — this class never evaluates conditions itself.
 */
class GiftSync
{
    /** Marks a gift line this class removes, so the removal is not recorded as the shopper declining it. */
    public const AUTO_REMOVED_FLAG = 'magenx_gift_auto_removed';

    public const DECLINED_FIELD = 'magenx_declined_gift_rules';

    private const GIFTABLE_TYPES = [
        ProductType::TYPE_SIMPLE,
        ProductType::TYPE_VIRTUAL,
        // Magento_Downloadable's type code, named here so this module does not
        // depend on that module.
        'downloadable',
    ];

    /**
     * @param Config $config
     * @param GiftRules $giftRules
     * @param GiftLine $giftLine
     * @param ProductRepositoryInterface $productRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly GiftRules $giftRules,
        private readonly GiftLine $giftLine,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sync the gift lines. Returns whether the cart changed (totals have then
     * been re-collected).
     *
     * @param Quote $quote
     * @return bool
     */
    public function sync(Quote $quote): bool
    {
        if (!$quote->getIsActive()) {
            return false;
        }

        $enabled = $this->config->isGiftEnabled((int) $quote->getStoreId());
        $rules = $enabled ? $this->giftRules->getActiveRules() : [];
        $giftLines = $this->getGiftLines($quote);
        if ($rules === [] && $giftLines === []) {
            return false;
        }

        // Always re-collect: the caller may have changed items after an earlier
        // collection in this request, and applied_rule_ids must reflect the cart
        // as it is about to be saved.
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $applied = array_flip(array_filter(array_map('intval', explode(',', (string) $quote->getAppliedRuleIds()))));
        $declined = $this->getDeclinedRuleIds($quote);

        $changed = false;
        foreach ($giftLines as $ruleId => $item) {
            if (!isset($rules[$ruleId], $applied[$ruleId])) {
                $this->removeLine($quote, $item);
                $changed = true;
            } elseif ((float) $item->getQty() !== (float) $rules[$ruleId]['qty']) {
                $item->setQty($rules[$ruleId]['qty']);
                $changed = true;
            }
        }

        foreach ($rules as $ruleId => $rule) {
            if (isset($applied[$ruleId]) && !isset($giftLines[$ruleId]) && !isset($declined[$ruleId])
                && $this->addLine($quote, $ruleId, $rule['sku'], $rule['qty'])
            ) {
                $changed = true;
            }
        }

        if ($changed) {
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
        }

        return $changed;
    }

    /**
     * Current gift lines, keyed by rule id.
     *
     * @param Quote $quote
     * @return array<int, Item>
     */
    private function getGiftLines(Quote $quote): array
    {
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $ruleId = $this->giftLine->getRuleId($item);
            if ($ruleId !== null && !$item->isDeleted()) {
                if (isset($lines[$ruleId])) {
                    // Two lines for one rule (e.g. two requests racing): keep one.
                    $this->removeLine($quote, $item);
                    continue;
                }
                $lines[$ruleId] = $item;
            }
        }

        return $lines;
    }

    /**
     * @param Quote $quote
     * @return array<int, true>
     */
    private function getDeclinedRuleIds(Quote $quote): array
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $quote->getData(self::DECLINED_FIELD))));

        return array_fill_keys($ids, true);
    }

    /**
     * @param Quote $quote
     * @param Item $item
     * @return void
     */
    private function removeLine(Quote $quote, Item $item): void
    {
        $item->setData(self::AUTO_REMOVED_FLAG, true);
        if ($item->getId()) {
            $quote->removeItem($item->getId());
        } else {
            $quote->deleteItem($item);
        }
    }

    /**
     * Add the gift at price 0. A gift that cannot be added (unknown SKU, out of
     * stock, disabled, a type that needs options) is skipped and logged — the
     * cart itself must never fail because of a misconfigured gift.
     *
     * @param Quote $quote
     * @param int $ruleId
     * @param string $sku
     * @param int $qty
     * @return bool
     */
    private function addLine(Quote $quote, int $ruleId, string $sku, int $qty): bool
    {
        try {
            $product = $this->productRepository->get($sku, false, (int) $quote->getStoreId(), true);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning(sprintf('Magenx_DealGraphQl: gift SKU "%s" of rule %d does not exist.', $sku, $ruleId));
            return false;
        }

        if (!in_array($product->getTypeId(), self::GIFTABLE_TYPES, true)) {
            $this->logger->warning(sprintf(
                'Magenx_DealGraphQl: gift SKU "%s" of rule %d is a %s product; only simple, virtual and downloadable products can be gifts.',
                $sku,
                $ruleId,
                $product->getTypeId()
            ));
            return false;
        }

        // The option makes the gift its own line (quote items compare options),
        // and is how every other part of the module recognises it.
        $product->addCustomOption(GiftLine::OPTION_CODE, (string) $ruleId);

        try {
            $item = $quote->addProduct($product, new DataObject(['qty' => $qty]));
        } catch (LocalizedException $e) {
            $this->logger->info(sprintf(
                'Magenx_DealGraphQl: gift SKU "%s" of rule %d was not added: %s',
                $sku,
                $ruleId,
                $e->getMessage()
            ));
            return false;
        }

        if (!$item instanceof Item) {
            // addProduct reports some failures (e.g. out of stock) as a string.
            $this->logger->info(sprintf(
                'Magenx_DealGraphQl: gift SKU "%s" of rule %d was not added: %s',
                $sku,
                $ruleId,
                is_string($item) ? $item : 'unknown reason'
            ));
            return false;
        }

        $item->setCustomPrice(0);
        $item->setOriginalCustomPrice(0);
        $item->getProduct()->setIsSuperMode(true);

        return true;
    }
}
