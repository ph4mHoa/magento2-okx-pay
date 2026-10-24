<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Payment;

/**
 * Places the order as "pending payment". The amount to pay is set once the order exists, by the start
 * controller, because it has to be unique among the open payments.
 */
class InitializeCommand implements CommandInterface
{
    /**
     * @param OrderConfig $orderConfig
     */
    public function __construct(
        private readonly OrderConfig $orderConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(array $commandSubject)
    {
        $stateObject = SubjectReader::readStateObject($commandSubject);
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        // The confirmation email goes out once the payment is received.
        $payment->getOrder()->setCanSendNewEmailFlag(false);

        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', $this->orderConfig->getStateDefaultStatus(Order::STATE_PENDING_PAYMENT));
        $stateObject->setData('is_notified', false);

        return null;
    }
}
