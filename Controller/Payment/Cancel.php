<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Controller\Payment;

use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The shopper gives up: cancel the order and put the items back in the cart, unless the money already arrived.
 */
class Cancel implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request
     * @param PaymentRepository $payments
     * @param PaymentProcessor $processor
     * @param PaymentRegistrar $registrar
     * @param CheckoutSession $checkoutSession
     * @param RedirectFactory $redirectFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PaymentRepository $payments,
        private readonly PaymentProcessor $processor,
        private readonly PaymentRegistrar $registrar,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): RedirectResult
    {
        $redirect = $this->redirectFactory->create();
        $payment = $this->payments->getByToken((string)$this->request->getParam('token'));
        if ($payment === null) {
            return $redirect->setPath('checkout/cart');
        }

        try {
            $payment = $this->registrar->close(
                $this->processor->refresh($payment),
                Payment::STATUS_CANCELED,
                __('The customer cancelled the OKX payment.')
            );
        } catch (\Exception $e) {
            $this->logger->error('Could not cancel the OKX payment', [
                'order_id' => $payment->getOrderId(),
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(__('The payment could not be cancelled. Please try again.'));
        }

        if ($payment->getStatus() !== Payment::STATUS_CANCELED) {
            return $redirect->setPath('joepham_okxpay/payment/view', ['token' => $payment->getAccessToken()]);
        }
        if ((int)$this->checkoutSession->getLastOrderId() === (int)$payment->getOrderId()) {
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addNoticeMessage(__('The payment was cancelled and your cart has been restored.'));
        } else {
            $this->messageManager->addNoticeMessage(__('The payment was cancelled.'));
        }

        return $redirect->setPath('checkout/cart');
    }
}
