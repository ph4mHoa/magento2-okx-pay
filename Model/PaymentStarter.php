<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use JoePham\OkxPay\Model\XLayer\RpcClient;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Sets up the payment of a freshly placed order: its unique amount, payment window and page link.
 */
class PaymentStarter
{
    /**
     * @param Config $config
     * @param PaymentRepository $payments
     * @param PaymentFactory $paymentFactory
     * @param AmountAllocator $amountAllocator
     * @param UsdConverter $usdConverter
     * @param OrderLocker $locker
     * @param RpcClient $rpcClient
     * @param OrderPaymentRepositoryInterface $orderPaymentRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly PaymentRepository $payments,
        private readonly PaymentFactory $paymentFactory,
        private readonly AmountAllocator $amountAllocator,
        private readonly UsdConverter $usdConverter,
        private readonly OrderLocker $locker,
        private readonly RpcClient $rpcClient,
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository
    ) {
    }

    /**
     * The order's payment, created on the first call.
     *
     * @param Order $order
     * @return Payment
     * @throws LocalizedException
     */
    public function start(Order $order): Payment
    {
        $orderId = (int)$order->getId();
        $existing = $this->payments->getByOrderId($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $method = (string)$order->getPayment()?->getMethod();
        if (!in_array($method, Config::METHODS, true)) {
            throw new LocalizedException(__('The order was not placed with an OKX payment method.'));
        }
        $usdAmount = $this->usdConverter->toUsd($order);
        // Transfers mined before the order existed can never pay it.
        $startBlock = $method === Config::WALLET ? (string)$this->rpcClient->getBlockNumber() : null;

        $payment = $this->locker->runAllocation(
            function () use ($orderId, $method, $usdAmount, $startBlock): Payment {
                $existing = $this->payments->getByOrderId($orderId);
                if ($existing !== null) {
                    return $existing;
                }
                $payment = $this->paymentFactory->create();
                $payment->setOrderId($orderId)
                    ->setMethod($method)
                    ->setStatus(Payment::STATUS_PENDING)
                    ->setUsdAmount($usdAmount)
                    ->setAmount($this->amountAllocator->allocate($method, $usdAmount))
                    ->setAccessToken(bin2hex(random_bytes(16)))
                    ->setStartBlock($startBlock)
                    ->setExpiresAt(gmdate('Y-m-d H:i:s', time() + 60 * $this->config->getPaymentWindow($method)));

                return $this->payments->save($payment);
            }
        );

        $orderPayment = $order->getPayment();
        $orderPayment->setAdditionalInformation(
            PaymentInfo::AMOUNT,
            bcadd((string)$payment->getAmount(), '0', 4) . ' ' . implode('/', $this->config->getCoinNames($method))
        );
        $this->orderPaymentRepository->save($orderPayment);

        return $payment;
    }
}
