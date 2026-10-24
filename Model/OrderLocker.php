<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Serialises work on one order (cron, status checks, the shopper cancelling), the amount allocation and the
 * scans for incoming transfers.
 */
class OrderLocker
{
    private const PREFIX = 'joepham_okxpay_order_';
    private const ALLOCATION_LOCK = 'joepham_okxpay_allocation';
    private const SCAN_PREFIX = 'joepham_okxpay_scan_';
    private const TIMEOUT = 30;

    /**
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Run $callback while holding the order's lock.
     *
     * @param int $orderId
     * @param callable $callback
     * @return mixed
     * @throws LocalizedException when the lock is busy
     */
    public function run(int $orderId, callable $callback): mixed
    {
        return $this->withLock(self::PREFIX . $orderId, self::TIMEOUT, $callback);
    }

    /**
     * Run $callback while holding the amount allocation lock.
     *
     * @param callable $callback
     * @return mixed
     * @throws LocalizedException when the lock is busy
     */
    public function runAllocation(callable $callback): mixed
    {
        return $this->withLock(self::ALLOCATION_LOCK, self::TIMEOUT, $callback);
    }

    /**
     * Run a scan unless another process is already running it.
     *
     * @param string $name
     * @param callable $callback
     * @return bool false when the scan was skipped
     */
    public function tryScan(string $name, callable $callback): bool
    {
        $lock = self::SCAN_PREFIX . $name;
        if (!$this->lockManager->lock($lock, 0)) {
            return false;
        }
        try {
            $callback();
        } finally {
            $this->lockManager->unlock($lock);
        }

        return true;
    }

    /**
     * Hold a named lock around $callback.
     *
     * @param string $name
     * @param int $timeout
     * @param callable $callback
     * @return mixed
     * @throws LocalizedException
     */
    private function withLock(string $name, int $timeout, callable $callback): mixed
    {
        if (!$this->lockManager->lock($name, $timeout)) {
            throw new LocalizedException(__('The payment is being processed. Please try again in a moment.'));
        }
        try {
            return $callback();
        } finally {
            $this->lockManager->unlock($name);
        }
    }
}
