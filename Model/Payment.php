<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use Magento\Framework\Model\AbstractModel;

/**
 * An OKX payment the module waits for: one per order.
 *
 * @method int getOrderId()
 * @method $this setOrderId(int $orderId)
 * @method string getMethod()
 * @method $this setMethod(string $method)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method string getAmount()
 * @method $this setAmount(string $amount)
 * @method string getUsdAmount()
 * @method $this setUsdAmount(string $usdAmount)
 * @method string|null getCoin()
 * @method $this setCoin(?string $coin)
 * @method string|null getReference()
 * @method $this setReference(?string $reference)
 * @method string|null getPayer()
 * @method $this setPayer(?string $payer)
 * @method string|null getTxHash()
 * @method $this setTxHash(?string $txHash)
 * @method string getAccessToken()
 * @method $this setAccessToken(string $accessToken)
 * @method string|null getStartBlock()
 * @method $this setStartBlock(?string $startBlock)
 * @method string getExpiresAt()
 * @method $this setExpiresAt(string $expiresAt)
 * @method string|null getPaidAt()
 * @method $this setPaidAt(?string $paidAt)
 * @method string getCreatedAt()
 * @method $this setCreatedAt(string $createdAt)
 */
class Payment extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';
    /** Money arrived after the order was cancelled: the merchant has to refund or re-create the order. */
    public const STATUS_LATE = 'late';

    /** Seconds added to the window of a payment whose transaction was submitted. */
    public const SUBMITTED_GRACE = 600;

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(PaymentResource::class);
    }

    /**
     * Still waiting for the shopper.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->getStatus() === self::STATUS_PENDING;
    }

    /**
     * Payment window is over (UTC timestamps).
     *
     * A payment the shopper's wallet already sent gets a few more minutes, so a transaction confirmed just after
     * the deadline still pays the order instead of arriving late.
     *
     * @return bool
     */
    public function isOverdue(): bool
    {
        $grace = (string)$this->getTxHash() !== '' ? self::SUBMITTED_GRACE : 0;

        return strtotime($this->getExpiresAt() . ' UTC') + $grace < time();
    }

    /**
     * Status to show the shopper: an open payment whose window is over shows as expired before cron closes it.
     *
     * @return string
     */
    public function getDisplayStatus(): string
    {
        return $this->isPending() && $this->isOverdue() ? self::STATUS_EXPIRED : (string)$this->getStatus();
    }

    /**
     * Seconds the payment window has left, 0 when it is over.
     *
     * @return int
     */
    public function getSecondsLeft(): int
    {
        return max(0, strtotime($this->getExpiresAt() . ' UTC') - time());
    }
}
