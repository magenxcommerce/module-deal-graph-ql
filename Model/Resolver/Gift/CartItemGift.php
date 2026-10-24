<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model\Resolver\Gift;

use Magenx\DealGraphQl\Model\Gift\GiftLine;
use Magenx\DealGraphQl\Model\Gift\GiftRules;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Resolves `CartItemInterface.deal_gift`: the gift rule a cart line was added
 * by, or null for an ordinary line.
 *
 * Reads the item option already loaded with the cart; the labels come from
 * {@see GiftRules}, which loads every gift rule's labels in one query per
 * request — so a cart with many lines costs at most one extra query, and none
 * at all when the store has no gift rules.
 */
class CartItemGift implements ResolverInterface
{
    /**
     * @param GiftLine $giftLine
     * @param GiftRules $giftRules
     */
    public function __construct(
        private readonly GiftLine $giftLine,
        private readonly GiftRules $giftRules
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $item = $value['model'] ?? null;
        if (!$item instanceof AbstractItem) {
            return null;
        }

        $ruleId = $this->giftLine->getRuleId($item);
        if ($ruleId === null) {
            return null;
        }

        $label = $this->giftRules->getLabel(
            $ruleId,
            (int) $context->getExtensionAttributes()->getStore()->getId()
        );

        return [
            'rule_id' => $ruleId,
            'label' => $label !== '' ? $label : null,
        ];
    }
}
