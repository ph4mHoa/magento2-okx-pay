<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

/**
 * Money that arrived for a payment: an OKX internal transfer or an X Layer token transfer.
 */
class Transfer
{
    /**
     * @param string $coin coin or token code, e.g. USDT or USDT0
     * @param string $amount e.g. "35.6037"
     * @param string $reference unique per transfer: OKX deposit ID, or transaction hash and log index
     * @param string $transactionId shown on the order: deposit ID or transaction hash
     * @param string $payer masked OKX account or sending address
     */
    public function __construct(
        public readonly string $coin,
        public readonly string $amount,
        public readonly string $reference,
        public readonly string $transactionId,
        public readonly string $payer
    ) {
    }
}
