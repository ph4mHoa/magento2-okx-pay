<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Cron;

use JoePham\OkxPay\Model\PaymentProcessor;

/**
 * Every minute: record OKX transfers and cancel orders that stayed unpaid.
 */
class ProcessPayments
{
    /**
     * @param PaymentProcessor $processor
     */
    public function __construct(
        private readonly PaymentProcessor $processor
    ) {
    }

    /**
     * Run the searches.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->processor->processAll();
    }
}
