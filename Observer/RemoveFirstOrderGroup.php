<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Observer;

use Magenx\DealGraphQl\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Restores a customer to the default group once they place their first order,
 * so the first-order deal (a Catalog Price Rule scoped to the "first order"
 * group) no longer applies to their subsequent carts.
 *
 * Runs on sales_model_service_quote_submit_success — the safe post-commit hook
 * that fires for GraphQL placeOrder as well as Luma (unlike checkout_submit_all_after,
 * which is Luma-only). Because it runs after totals are collected, the first
 * order keeps the discount; only later carts lose it.
 *
 * Guarded to only move a customer who is currently in the configured first-order
 * group (never clobbering a manually-assigned group), and to swallow failures so
 * a group-save hiccup can never fail an otherwise-placed order.
 */
class RemoveFirstOrderGroup implements ObserverInterface
{
    /**
     * @param Config $config
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        if ($order->getCustomerIsGuest()) {
            return;
        }

        $customerId = (int) $order->getCustomerId();
        if ($customerId <= 0) {
            return;
        }

        $storeId = $order->getStoreId() !== null ? (int) $order->getStoreId() : null;
        if (!$this->config->isFirstOrderGroupEnabled($storeId)) {
            return;
        }

        $newGroup = $this->config->getNewCustomersGroupId($storeId);
        if ($newGroup <= 0) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);

            // Only restore customers we placed in the first-order group; leave
            // manually-assigned groups untouched.
            if ((int) $customer->getGroupId() !== $newGroup) {
                return;
            }

            $customer->setGroupId($this->config->getRestoreGroupId($storeId));
            $this->customerRepository->save($customer);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Magenx_DealGraphQl: failed to restore customer group after first order. ' . $e->getMessage()
            );
        }
    }
}
