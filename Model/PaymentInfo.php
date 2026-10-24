<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

/**
 * Keys of the order payment's additional information.
 */
class PaymentInfo
{
    /** Amount asked for, e.g. "35.6037 USDT". */
    public const AMOUNT = 'okx_amount';
    /** Amount and coin received. */
    public const RECEIVED = 'okx_received';
    /** OKX deposit ID or X Layer transaction hash. */
    public const REFERENCE = 'okx_reference';
    /** Masked OKX account or wallet address that paid. */
    public const PAYER = 'okx_payer';
}
