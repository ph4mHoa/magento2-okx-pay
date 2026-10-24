<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use JoePham\OkxPay\Model\ResourceModel\Payment\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Loads and saves OKX payments.
 */
class PaymentRepository
{
    /**
     * @param PaymentFactory $paymentFactory
     * @param PaymentResource $resource
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly PaymentFactory $paymentFactory,
        private readonly PaymentResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Payment by ID, read from the database.
     *
     * @param int $id
     * @return Payment
     * @throws NoSuchEntityException
     */
    public function getById(int $id): Payment
    {
        $payment = $this->paymentFactory->create();
        $this->resource->load($payment, $id);
        if (!$payment->getId()) {
            throw new NoSuchEntityException(__('OKX payment %1 does not exist.', $id));
        }

        return $payment;
    }

    /**
     * Payment of an order, if one was started.
     *
     * @param int $orderId
     * @return Payment|null
     */
    public function getByOrderId(int $orderId): ?Payment
    {
        return $this->getBy('order_id', (string)$orderId);
    }

    /**
     * Payment behind a payment page link.
     *
     * @param string $token
     * @return Payment|null
     */
    public function getByToken(string $token): ?Payment
    {
        return preg_match('/^[0-9a-f]{32}$/', $token) ? $this->getBy('access_token', $token) : null;
    }

    /**
     * Save a payment.
     *
     * @param Payment $payment
     * @return Payment
     */
    public function save(Payment $payment): Payment
    {
        $this->resource->save($payment);

        return $payment;
    }

    /**
     * Whether a transfer was already recorded against a payment.
     *
     * @param string $reference
     * @return bool
     */
    public function isReferenceUsed(string $reference): bool
    {
        return $this->getBy('reference', $reference) !== null;
    }

    /**
     * Payments a transfer can still be matched to.
     *
     * Open ones, and those closed without payment while their amount is reserved, so that money arriving late
     * is noticed.
     *
     * @param string $method
     * @return Payment[]
     */
    public function getWatched(string $method): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('method', $method)
            ->addFieldToFilter('status', ['in' => [
                Payment::STATUS_PENDING,
                Payment::STATUS_EXPIRED,
                Payment::STATUS_CANCELED,
            ]]);
        $collection->getSelect()->where(
            $collection->getConnection()->quoteInto('status = ?', Payment::STATUS_PENDING)
            . ' OR '
            . $collection->getConnection()->quoteInto(
                'expires_at > ?',
                gmdate('Y-m-d H:i:s', time() - AmountAllocator::RESERVE_SECONDS)
            )
        );
        $collection->setOrder('created_at', 'ASC');

        return array_values($collection->getItems());
    }

    /**
     * Open payments whose window ended a while ago.
     *
     * @param string $method
     * @param int $delay seconds past the window, so a transfer sent at the last moment can still be found
     * @return Payment[]
     */
    public function getOverdue(string $method, int $delay): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('method', $method)
            ->addFieldToFilter('status', Payment::STATUS_PENDING)
            ->addFieldToFilter('expires_at', ['lt' => gmdate('Y-m-d H:i:s', time() - $delay)]);

        return array_values(array_filter($collection->getItems(), static fn (Payment $p): bool => $p->isOverdue()));
    }

    /**
     * Load one payment by a unique column.
     *
     * @param string $field
     * @param string $value
     * @return Payment|null
     */
    private function getBy(string $field, string $value): ?Payment
    {
        $payment = $this->paymentFactory->create();
        $this->resource->load($payment, $value, $field);

        return $payment->getId() ? $payment : null;
    }
}
