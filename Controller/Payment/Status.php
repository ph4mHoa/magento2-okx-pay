<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Controller\Payment;

use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\PaymentRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;

/**
 * Polled by the payment page: looks for the transfer and reports the payment status.
 */
class Status implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param PaymentRepository $payments
     * @param PaymentProcessor $processor
     * @param CheckoutSession $checkoutSession
     * @param JsonFactory $jsonFactory
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PaymentRepository $payments,
        private readonly PaymentProcessor $processor,
        private readonly CheckoutSession $checkoutSession,
        private readonly JsonFactory $jsonFactory,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $payment = $this->payments->getByToken((string)$this->request->getParam('token'));
        if ($payment === null) {
            return $result->setHttpResponseCode(404)->setData(['status' => 'unknown']);
        }

        $payment = $this->processor->refresh($payment);
        $status = $payment->getDisplayStatus();
        $sameSession = (int)$this->checkoutSession->getLastOrderId() === (int)$payment->getOrderId();

        return $result->setData([
            'status' => $status,
            'secondsLeft' => $payment->getSecondsLeft(),
            'redirect' => $status === Payment::STATUS_PAID && $sameSession
                ? $this->urlBuilder->getUrl('checkout/onepage/success')
                : null,
        ]);
    }
}
