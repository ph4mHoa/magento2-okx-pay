<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Controller\Payment;

use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * The shopper's wallet sent the transaction: remember its hash and check it straight away.
 *
 * The hash only speeds things up. It is checked on chain like any other transfer, so a wrong one pays nothing.
 */
class Submit implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request
     * @param PaymentRepository $payments
     * @param PaymentRegistrar $registrar
     * @param PaymentProcessor $processor
     * @param JsonFactory $jsonFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PaymentRepository $payments,
        private readonly PaymentRegistrar $registrar,
        private readonly PaymentProcessor $processor,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $payment = $this->payments->getByToken((string)$this->request->getParam('token'));
        $txHash = strtolower(trim((string)$this->request->getParam('tx_hash')));
        if ($payment === null || !preg_match('/^0x[0-9a-f]{64}$/', $txHash)) {
            return $result->setHttpResponseCode(400)->setData(['status' => 'unknown']);
        }

        try {
            $payment = $this->processor->refresh($this->registrar->submitTransaction($payment, $txHash));
        } catch (\Exception $e) {
            $this->logger->warning('Could not record the wallet transaction', [
                'order_id' => $payment->getOrderId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $result->setData([
            'status' => $payment->getDisplayStatus(),
            'secondsLeft' => $payment->getSecondsLeft(),
        ]);
    }
}
