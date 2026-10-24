<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Gives each payment an amount no other open payment of the same method has.
 *
 * Neither an OKX internal transfer nor a token transfer carries an order reference, so the amount is the
 * reference: the order total plus 0.0001 to 0.0999, the smallest addition that is free. Amounts stay reserved
 * for a day after the payment window, so a late or repeated transfer is not taken for another order's payment.
 * Call it inside the allocation lock, and save the payment before releasing the lock.
 */
class AmountAllocator
{
    public const RESERVE_SECONDS = 86400;
    private const STEP = '0.0001';
    private const MAX_STEPS = 999;

    /**
     * @param PaymentResource $paymentResource
     */
    public function __construct(
        private readonly PaymentResource $paymentResource
    ) {
    }

    /**
     * Amount to ask for, e.g. "35.6001" for a 35.60 order.
     *
     * @param string $method
     * @param string $usdAmount order total, 2 decimals
     * @return string
     * @throws LocalizedException when every amount for this total is taken
     */
    public function allocate(string $method, string $usdAmount): string
    {
        $first = bcadd($usdAmount, self::STEP, 4);
        $last = bcadd($usdAmount, bcmul(self::STEP, (string)self::MAX_STEPS, 4), 4);
        $taken = [];
        foreach ($this->paymentResource->getReservedAmounts(
            $method,
            $first,
            $last,
            gmdate('Y-m-d H:i:s', time() - self::RESERVE_SECONDS)
        ) as $amount) {
            $taken[bcadd((string)$amount, '0', 4)] = true;
        }

        for ($step = 1; $step <= self::MAX_STEPS; $step++) {
            $amount = bcadd($usdAmount, bcmul(self::STEP, (string)$step, 4), 4);
            if (!isset($taken[$amount])) {
                return $amount;
            }
        }

        throw new LocalizedException(
            __('Too many payments for this amount are open. Please try again later or choose another payment method.')
        );
    }
}
