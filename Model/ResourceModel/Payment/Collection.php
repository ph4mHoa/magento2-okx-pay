<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\ResourceModel\Payment;

use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * OKX payments.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(Payment::class, PaymentResource::class);
    }
}
