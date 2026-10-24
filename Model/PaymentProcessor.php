<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use JoePham\OkxPay\Model\Okx\DepositMatcher;
use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use JoePham\OkxPay\Model\XLayer\TransferMatcher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Runs the transfer searches: every minute from cron, and on demand while a shopper waits on the payment page.
 */
class PaymentProcessor
{
    /** Seconds between searches started by payment pages; cron runs regardless. */
    private const PAGE_SCAN_INTERVAL = 10;
    /** Seconds between checks of the transaction a wallet reported. */
    private const TX_CHECK_INTERVAL = 3;
    private const CRON_RANGES = 60;
    private const PAGE_RANGES = 3;
    /** Seconds past the window before an unpaid order is cancelled: room for a transfer sent at the last moment. */
    private const EXPIRE_DELAY = 120;
    /** Orders whose shopper never reached the payment page are cancelled after this many seconds. */
    private const ABANDON_AFTER = 3600;
    private const BATCH_SIZE = 200;

    /**
     * @param Config $config
     * @param DepositMatcher $depositMatcher
     * @param TransferMatcher $transferMatcher
     * @param PaymentRepository $payments
     * @param PaymentRegistrar $registrar
     * @param OrderLocker $locker
     * @param CacheInterface $cache
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly DepositMatcher $depositMatcher,
        private readonly TransferMatcher $transferMatcher,
        private readonly PaymentRepository $payments,
        private readonly PaymentRegistrar $registrar,
        private readonly OrderLocker $locker,
        private readonly CacheInterface $cache,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Cron: record new transfers, then cancel what stayed unpaid.
     *
     * Unpaid payments are only expired after a complete search, so an outage of the OKX API or the RPC never
     * cancels an order that was paid.
     *
     * @return void
     */
    public function processAll(): void
    {
        foreach (Config::METHODS as $method) {
            if ($this->search($method, self::CRON_RANGES)) {
                $this->expireOverdue($method);
            }
        }
        $this->cancelUnstarted();
    }

    /**
     * A payment page asks for news: search now unless a search ran moments ago.
     *
     * @param Payment $payment
     * @return Payment the payment as it is afterwards
     */
    public function refresh(Payment $payment): Payment
    {
        if (!$payment->isPending()) {
            return $payment;
        }
        $method = (string)$payment->getMethod();
        if ($method === Config::WALLET
            && (string)$payment->getTxHash() !== ''
            && $this->throttle('tx_' . $payment->getId(), self::TX_CHECK_INTERVAL)
        ) {
            try {
                $this->transferMatcher->checkTransaction($payment);
            } catch (\Exception $e) {
                $this->logger->warning('X Layer transaction check failed', [
                    'order_id' => $payment->getOrderId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($this->throttle('scan_' . $method, self::PAGE_SCAN_INTERVAL)) {
            $this->search($method, self::PAGE_RANGES);
        }

        return $this->payments->getById((int)$payment->getId());
    }

    /**
     * Search for transfers of one method.
     *
     * @param string $method
     * @param int $ranges block ranges the X Layer scan may read
     * @return bool whether the search ran and covered everything up to now
     */
    private function search(string $method, int $ranges): bool
    {
        $configured = $method === Config::APP ? $this->config->isAppConfigured() : $this->config->isWalletConfigured();
        if (!$configured) {
            return false;
        }

        $complete = false;
        try {
            $this->locker->tryScan($method, function () use ($method, $ranges, &$complete): void {
                if ($method === Config::APP) {
                    $this->depositMatcher->run();
                    $complete = true;
                } else {
                    $complete = $this->transferMatcher->scan($ranges);
                }
            });
        } catch (\Exception $e) {
            $this->logger->error('OKX payment search failed', ['method' => $method, 'error' => $e->getMessage()]);
        }

        return $complete;
    }

    /**
     * Cancel the orders of payments whose window is over.
     *
     * @param string $method
     * @return void
     */
    private function expireOverdue(string $method): void
    {
        foreach ($this->payments->getOverdue($method, self::EXPIRE_DELAY) as $payment) {
            try {
                $this->registrar->close(
                    $payment,
                    Payment::STATUS_EXPIRED,
                    __('No OKX payment arrived within the payment window. The order was cancelled.')
                );
            } catch (\Exception $e) {
                $this->logger->error('Could not expire an OKX payment', [
                    'order_id' => $payment->getOrderId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Cancel orders placed with an OKX method whose payment page was never opened.
     *
     * @return void
     */
    private function cancelUnstarted(): void
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('main_table.state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('main_table.created_at', ['lt' => gmdate('Y-m-d H:i:s', time() - self::ABANDON_AFTER)]);
        $select = $collection->getSelect()
            ->reset(Select::COLUMNS)
            ->columns(['main_table.entity_id'])
            ->join(
                ['payment' => $collection->getTable('sales_order_payment')],
                'payment.parent_id = main_table.entity_id',
                []
            )
            ->joinLeft(
                ['okx' => $collection->getTable(PaymentResource::TABLE)],
                'okx.order_id = main_table.entity_id',
                []
            )
            ->where('payment.method IN (?)', Config::METHODS)
            ->where('okx.entity_id IS NULL')
            ->limit(self::BATCH_SIZE);

        foreach ($collection->getConnection()->fetchCol($select) as $orderId) {
            try {
                $this->registrar->cancelUnstarted(
                    (int)$orderId,
                    __('The customer did not open the OKX payment page. The order was cancelled.')
                );
            } catch (\Exception $e) {
                $this->logger->error('Could not cancel an unstarted OKX order', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Allow an action at most once per $seconds across all requests.
     *
     * @param string $key
     * @param int $seconds
     * @return bool
     */
    private function throttle(string $key, int $seconds): bool
    {
        $cacheKey = 'joepham_okxpay_' . $key;
        if ($this->cache->load($cacheKey) !== false) {
            return false;
        }
        $this->cache->save('1', $cacheKey, [], $seconds);

        return true;
    }
}
