<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Controller\Payment;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\PaymentStarter;
use JoePham\OkxPay\Model\XLayer\RpcException;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Right after "Place Order": give the order its amount to pay and open the payment page.
 */
class Start implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param PaymentRepository $payments
     * @param PaymentStarter $paymentStarter
     * @param PaymentRegistrar $registrar
     * @param RedirectFactory $redirectFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PaymentRepository $payments,
        private readonly PaymentStarter $paymentStarter,
        private readonly PaymentRegistrar $registrar,
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
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order->getId() || !in_array($order->getPayment()?->getMethod(), Config::METHODS, true)) {
            return $redirect->setPath('checkout/cart');
        }

        $payment = $this->payments->getByOrderId((int)$order->getId());
        if ($payment === null) {
            if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
                return $redirect->setPath('checkout/cart');
            }
            try {
                $payment = $this->paymentStarter->start($order);
            } catch (\Exception $e) {
                return $redirect->setPath($this->giveUp($order, $e));
            }
        }

        return $redirect->setPath('joepham_okxpay/payment/view', ['token' => $payment->getAccessToken()]);
    }

    /**
     * Cancel the order, put the items back in the cart and say why.
     *
     * @param Order $order
     * @param \Exception $error
     * @return string route path
     */
    private function giveUp(Order $order, \Exception $error): string
    {
        $this->logger->error('Could not start the OKX payment', [
            'order' => $order->getIncrementId(),
            'error' => $error->getMessage(),
        ]);
        try {
            $this->registrar->cancelUnstarted(
                (int)$order->getId(),
                __('The OKX payment could not be started: %1', $error->getMessage())
            );
            $this->checkoutSession->restoreQuote();
        } catch (\Exception $e) {
            $this->logger->error('Could not cancel the OKX order', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
        $this->messageManager->addErrorMessage(
            $error instanceof LocalizedException && !$error instanceof RpcException
                ? $error->getMessage()
                : __('The OKX payment could not be started. Please try again or choose another payment method.')
        );

        return 'checkout/cart';
    }
}
