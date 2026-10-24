<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Psr\Log\LoggerInterface;

/**
 * Applies what happened to a payment to its order: paid, late, expired or cancelled.
 *
 * Every change runs under the order's lock on freshly loaded data, so cron, the shopper's payment page and
 * the cancel button can race without paying or cancelling an order twice.
 */
class PaymentRegistrar
{
    /**
     * @param PaymentRepository $payments
     * @param PaymentResource $paymentResource
     * @param OrderLocker $locker
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentResource $paymentResource,
        private readonly OrderLocker $locker,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record a transfer whose amount matches $payment.
     *
     * The order is invoiced when it is still waiting for this payment. Money for a payment that was closed, or
     * whose order was changed meanwhile, is recorded as late and flagged on the order for the merchant.
     *
     * @param Payment $payment
     * @param Transfer $transfer
     * @return string|null the payment status afterwards, or null when the transfer was not recorded
     * @throws LocalizedException
     */
    public function registerTransfer(Payment $payment, Transfer $transfer): ?string
    {
        return $this->locker->run(
            (int)$payment->getOrderId(),
            function () use ($payment, $transfer): ?string {
                $payment = $this->payments->getById((int)$payment->getId());
                if ($this->payments->isReferenceUsed($transfer->reference)) {
                    return null;
                }
                $order = $this->loadOrder((int)$payment->getOrderId());

                if ($payment->isPending() && $order->getState() === Order::STATE_PENDING_PAYMENT) {
                    $this->markPaid($payment, $order, $transfer);
                } elseif (in_array(
                    $payment->getStatus(),
                    [Payment::STATUS_PENDING, Payment::STATUS_EXPIRED, Payment::STATUS_CANCELED],
                    true
                )) {
                    $this->markLate($payment, $order, $transfer);
                } else {
                    return null;
                }

                return $payment->getStatus();
            }
        );
    }

    /**
     * Close an unpaid payment and cancel its order.
     *
     * @param Payment $payment
     * @param string $status Payment::STATUS_EXPIRED or Payment::STATUS_CANCELED
     * @param Phrase $reason order comment
     * @return Payment the payment as it is afterwards
     * @throws LocalizedException
     */
    public function close(Payment $payment, string $status, Phrase $reason): Payment
    {
        return $this->locker->run(
            (int)$payment->getOrderId(),
            function () use ($payment, $status, $reason): Payment {
                $payment = $this->payments->getById((int)$payment->getId());
                if (!$payment->isPending()) {
                    return $payment;
                }
                $order = $this->loadOrder((int)$payment->getOrderId());
                $payment->setStatus($status);
                if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                    $order->cancel();
                    $order->addCommentToStatusHistory($reason);
                    $this->saveTogether($payment, $order);
                } else {
                    $this->payments->save($payment);
                }

                return $payment;
            }
        );
    }

    /**
     * Remember the transaction the shopper's wallet sent, so it can be checked before the logs are scanned.
     *
     * @param Payment $payment
     * @param string $txHash
     * @return Payment
     * @throws LocalizedException
     */
    public function submitTransaction(Payment $payment, string $txHash): Payment
    {
        return $this->locker->run(
            (int)$payment->getOrderId(),
            function () use ($payment, $txHash): Payment {
                $payment = $this->payments->getById((int)$payment->getId());
                if ($payment->isPending() && $payment->getMethod() === Config::WALLET) {
                    $this->payments->save($payment->setTxHash(strtolower($txHash)));
                }

                return $payment;
            }
        );
    }

    /**
     * Cancel an order whose payment was never started.
     *
     * The shopper did not reach the payment page, or the payment could not be set up.
     *
     * @param int $orderId
     * @param Phrase $reason
     * @return void
     * @throws LocalizedException
     */
    public function cancelUnstarted(int $orderId, Phrase $reason): void
    {
        $this->locker->run($orderId, function () use ($orderId, $reason): void {
            if ($this->payments->getByOrderId($orderId) !== null) {
                return;
            }
            $order = $this->loadOrder($orderId);
            if ($order->getState() === Order::STATE_PENDING_PAYMENT
                && in_array($order->getPayment()?->getMethod(), Config::METHODS, true)
            ) {
                $order->cancel();
                $order->addCommentToStatusHistory($reason);
                $this->orderRepository->save($order);
            }
        });
    }

    /**
     * Invoice the order and send the confirmation email.
     *
     * @param Payment $payment
     * @param Order $order
     * @param Transfer $transfer
     * @return void
     */
    private function markPaid(Payment $payment, Order $order, Transfer $transfer): void
    {
        $received = $transfer->amount . ' ' . $transfer->coin;
        $orderPayment = $order->getPayment();
        $orderPayment->setAdditionalInformation(PaymentInfo::RECEIVED, $received);
        $orderPayment->setAdditionalInformation(PaymentInfo::REFERENCE, $transfer->transactionId);
        $orderPayment->setAdditionalInformation(PaymentInfo::PAYER, $transfer->payer);
        $orderPayment->setTransactionId($transfer->transactionId);
        $orderPayment->setIsTransactionClosed(true);
        $orderPayment->registerCaptureNotification($order->getBaseGrandTotal(), true);
        $order->addCommentToStatusHistory(
            __('OKX payment received: %1, reference %2.', $received, $transfer->transactionId)
        );

        $this->record($payment, $transfer, Payment::STATUS_PAID)->setPaidAt(gmdate('Y-m-d H:i:s'));
        $this->saveTogether($payment, $order);

        if (!$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->logger->error('Could not send the order email', [
                    'order' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Keep the transfer and tell the merchant on the order; nothing else on the order changes.
     *
     * @param Payment $payment
     * @param Order $order
     * @param Transfer $transfer
     * @return void
     */
    private function markLate(Payment $payment, Order $order, Transfer $transfer): void
    {
        $this->payments->save($this->record($payment, $transfer, Payment::STATUS_LATE));
        $this->historyRepository->save($order->addCommentToStatusHistory(__(
            'An OKX payment of %1 arrived after the order stopped waiting for it (reference %2, from %3). '
            . 'Refund the customer, or fulfil the order by hand.',
            $transfer->amount . ' ' . $transfer->coin,
            $transfer->transactionId,
            $transfer->payer
        )));
        $this->logger->warning('OKX payment arrived late', [
            'order' => $order->getIncrementId(),
            'amount' => $transfer->amount . ' ' . $transfer->coin,
            'reference' => $transfer->reference,
        ]);
    }

    /**
     * Copy the transfer onto the payment.
     *
     * @param Payment $payment
     * @param Transfer $transfer
     * @param string $status
     * @return Payment
     */
    private function record(Payment $payment, Transfer $transfer, string $status): Payment
    {
        return $payment->setStatus($status)
            ->setCoin($transfer->coin)
            ->setReference($transfer->reference)
            ->setPayer(mb_substr($transfer->payer, 0, 255));
    }

    /**
     * Save the payment and the order in one database transaction.
     *
     * @param Payment $payment
     * @param Order $order
     * @return void
     * @throws \Exception
     */
    private function saveTogether(Payment $payment, Order $order): void
    {
        $connection = $this->paymentResource->getConnection();
        $connection->beginTransaction();
        try {
            $this->payments->save($payment);
            $this->orderRepository->save($order);
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Load the order straight from the database, inside the lock.
     *
     * @param int $orderId
     * @return Order
     * @throws NoSuchEntityException
     */
    private function loadOrder(int $orderId): Order
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId);
        if (!$order->getId()) {
            throw new NoSuchEntityException(__('Order %1 does not exist.', $orderId));
        }

        return $order;
    }
}
