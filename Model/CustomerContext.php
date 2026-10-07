<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\DealGraphQl\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the customer group id for the current GraphQL request.
 *
 * Catalog Price Rule prices (and therefore which rule/deal applies) are
 * per-customer-group, so the deal lookup must use the same group the storefront
 * prices are shown for: the logged-in customer's group, or NOT_LOGGED_IN (0) for
 * guests. Cached per request.
 */
class CustomerContext implements ResetAfterRequestInterface
{
    /** @var array<int, int> customer id => group id */
    private array $groupCache = [];

    /**
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->groupCache = [];
    }

    /**
     * The customer group id for the request.
     *
     * @param ContextInterface $context
     * @return int
     */
    public function getGroupId(ContextInterface $context): int
    {
        $userId = (int) $context->getUserId();
        if ($context->getExtensionAttributes()->getIsCustomer() !== true || $userId < 1) {
            return GroupInterface::NOT_LOGGED_IN_ID;
        }

        if (isset($this->groupCache[$userId])) {
            return $this->groupCache[$userId];
        }

        try {
            $groupId = (int) $this->customerRepository->getById($userId)->getGroupId();
        } catch (\Exception $e) {
            // Fall back to guest pricing rather than erroring the query, but say
            // so — silently downgrading a logged-in shopper hides a real fault.
            $this->logger->warning(
                'Magenx_DealGraphQl: could not resolve the customer group, using NOT_LOGGED_IN. ' . $e->getMessage()
            );
            $groupId = GroupInterface::NOT_LOGGED_IN_ID;
        }

        return $this->groupCache[$userId] = $groupId;
    }
}
