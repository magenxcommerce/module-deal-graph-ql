<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to the magenx_deal/* store configuration.
 *
 * Conditions, actions, pricing and schedule live on the native Catalog Price
 * Rules (see {@see DealProvider}); this config carries the feature toggle, the
 * deals-page cap, and the first-order customer-group automation settings.
 */
class Config
{
    private const XML_PATH_ENABLED = 'magenx_deal/general/enabled';
    private const XML_PATH_COUNT = 'magenx_deal/general/count';

    private const XML_PATH_FIRST_ORDER_ENABLED = 'magenx_deal/first_order/enabled';
    private const XML_PATH_FIRST_ORDER_NEW_GROUP = 'magenx_deal/first_order/new_customer_group';
    private const XML_PATH_FIRST_ORDER_RESTORE_GROUP = 'magenx_deal/first_order/restore_group';
    private const XML_PATH_FIRST_ORDER_DEFAULT_GROUP = 'magenx_deal/first_order/default_group';

    private const DEFAULT_COUNT = 48;
    private const DEFAULT_GENERAL_GROUP = 1;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the deals feature is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The maximum number of products the deals query returns.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getCount(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_COUNT, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_COUNT;
    }

    /**
     * Whether new customers are auto-assigned to a "first order" group on
     * registration (and restored to the default group after their first order).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isFirstOrderGroupEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_FIRST_ORDER_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The customer group new (first-order) customers are placed in. A group-scoped
     * Catalog Price Rule labelled as a first-order deal targets this group. Returns
     * 0 when unset (feature inert).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getNewCustomersGroupId(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_FIRST_ORDER_NEW_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The group a customer is moved back to once they place their first order
     * (typically General = 1).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getRestoreGroupId(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_FIRST_ORDER_RESTORE_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value > 0 ? $value : self::DEFAULT_GENERAL_GROUP;
    }

    /**
     * The group new registrations normally land in (typically General = 1). Only a
     * customer whose group is empty or equals this group is auto-assigned, so an
     * explicit non-default registration group (e.g. Wholesale) is never hijacked.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getDefaultGroupId(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_FIRST_ORDER_DEFAULT_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value > 0 ? $value : self::DEFAULT_GENERAL_GROUP;
    }
}
