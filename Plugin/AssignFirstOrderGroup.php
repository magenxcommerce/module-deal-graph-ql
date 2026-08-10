<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Plugin;

use Magenx\DealGraphQl\Model\Config;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts a newly-registered customer into the configured "first order" customer
 * group so a group-scoped, first-order-labelled Catalog Price Rule (see
 * {@see \Magenx\DealGraphQl\Model\DealProvider}) surfaces for them until they
 * place their first order (after which {@see \Magenx\DealGraphQl\Observer\RemoveFirstOrderGroup}
 * restores the default group).
 *
 * Hooks AccountManagementInterface::createAccount — the single seam every
 * registration channel funnels through (Luma Account\CreatePost, GraphQL
 * CreateCustomerV2, and Magenx_SocialLoginGraphQl), so one plugin covers them
 * all. Runs `before` so it mutates the customer prior to the single save (no
 * extra round trip).
 *
 * Guarded to only override an empty or default group, so an explicit
 * non-default registration group (e.g. Wholesale) is never hijacked.
 */
class AssignFirstOrderGroup
{
    /**
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param AccountManagementInterface $subject
     * @param CustomerInterface $customer
     * @param string|null $password
     * @param string $redirectUrl
     * @return array{0: CustomerInterface, 1: string|null, 2: string}|null
     */
    public function beforeCreateAccount(
        AccountManagementInterface $subject,
        CustomerInterface $customer,
        $password = null,
        $redirectUrl = ''
    ): ?array {
        try {
            $storeId = $customer->getStoreId() !== null ? (int) $customer->getStoreId() : null;

            if (!$this->config->isFirstOrderGroupEnabled($storeId)) {
                return null;
            }

            $newGroup = $this->config->getNewCustomersGroupId($storeId);
            $defaultGroup = $this->config->getDefaultGroupId($storeId);

            // Feature inert / misconfigured: no target group, or it equals the
            // default (assigning would be a no-op).
            if ($newGroup <= 0 || $newGroup === $defaultGroup) {
                return null;
            }

            $currentGroup = $customer->getGroupId();

            // Only touch registrations that carry no explicit group, or the
            // default one — never override Wholesale/etc.
            if ($currentGroup !== null && (int) $currentGroup !== $defaultGroup) {
                return null;
            }

            $customer->setGroupId($newGroup);
        } catch (\Throwable $e) {
            // Never let this break account creation.
            $this->logger->error(
                'Magenx_DealGraphQl: failed to assign first-order group on registration. ' . $e->getMessage()
            );

            return null;
        }

        return [$customer, $password, $redirectUrl];
    }
}
