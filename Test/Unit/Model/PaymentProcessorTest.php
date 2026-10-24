<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Okx\ApiException;
use JoePham\OkxPay\Model\Okx\DepositMatcher;
use JoePham\OkxPay\Model\OrderLocker;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\XLayer\TransferMatcher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PaymentProcessorTest extends TestCase
{
    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var DepositMatcher|MockObject
     */
    private $depositMatcher;

    /**
     * @var TransferMatcher|MockObject
     */
    private $transferMatcher;

    /**
     * @var PaymentRepository|MockObject
     */
    private $payments;

    /**
     * @var PaymentRegistrar|MockObject
     */
    private $registrar;

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * @var string[] order IDs without a payment
     */
    private array $unstarted = [];

    private PaymentProcessor $processor;

    /**
     * @var \Closure(Config): PaymentProcessor
     */
    private \Closure $makeProcessor;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isAppConfigured')->willReturn(true);
        $this->config->method('isWalletConfigured')->willReturn(true);
        $this->depositMatcher = $this->createMock(DepositMatcher::class);
        $this->transferMatcher = $this->createMock(TransferMatcher::class);
        $this->payments = $this->createMock(PaymentRepository::class);
        $this->registrar = $this->createMock(PaymentRegistrar::class);
        $locker = $this->createMock(OrderLocker::class);
        $locker->method('tryScan')->willReturnCallback(function (string $name, callable $callback): bool {
            $callback();
            return true;
        });
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $key) {
            $this->cache[$key] = $data;
            return true;
        });

        $select = $this->createMock(Select::class);
        foreach (['reset', 'columns', 'join', 'joinLeft', 'where', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchCol')->willReturnCallback(fn () => $this->unstarted);
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getTable')->willReturnArgument(0);
        $collectionFactory = $this->createMock(OrderCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $this->makeProcessor = fn (Config $config): PaymentProcessor => new PaymentProcessor(
            $config,
            $this->depositMatcher,
            $this->transferMatcher,
            $this->payments,
            $this->registrar,
            $locker,
            $cache,
            $collectionFactory,
            $this->createMock(LoggerInterface::class)
        );
        $this->processor = ($this->makeProcessor)($this->config);
    }

    public function testCronExpiresOnlyAfterACompleteSearch(): void
    {
        $this->depositMatcher->expects($this->once())->method('run');
        $this->transferMatcher->expects($this->once())->method('scan')->with(60)->willReturn(false);
        $overdue = $this->payment(Config::APP);
        $this->payments->expects($this->once())
            ->method('getOverdue')
            ->with(Config::APP, 120)
            ->willReturn([$overdue]);
        $this->registrar->expects($this->once())
            ->method('close')
            ->with($overdue, Payment::STATUS_EXPIRED, $this->anything());

        $this->processor->processAll();
    }

    public function testFailedSearchExpiresNothing(): void
    {
        $this->depositMatcher->method('run')->willThrowException(new ApiException(__('OKX down')));
        $this->transferMatcher->method('scan')->willThrowException(new \RuntimeException('RPC down'));
        $this->payments->expects($this->never())->method('getOverdue');
        $this->registrar->expects($this->never())->method('close');

        $this->processor->processAll();
    }

    public function testUnconfiguredMethodIsNeitherSearchedNorExpired(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isAppConfigured')->willReturn(false);
        $config->method('isWalletConfigured')->willReturn(false);
        $this->depositMatcher->expects($this->never())->method('run');
        $this->transferMatcher->expects($this->never())->method('scan');
        $this->payments->expects($this->never())->method('getOverdue');
        ($this->makeProcessor)($config)->processAll();
    }

    public function testOrdersWithoutAPaymentAreCancelled(): void
    {
        $this->unstarted = ['55', '56'];
        $this->transferMatcher->method('scan')->willReturn(true);
        $this->payments->method('getOverdue')->willReturn([]);
        $cancelled = [];
        $this->registrar->method('cancelUnstarted')->willReturnCallback(function (int $orderId) use (&$cancelled) {
            $cancelled[] = $orderId;
        });

        $this->processor->processAll();

        $this->assertSame([55, 56], $cancelled);
    }

    public function testPagePollChecksTheWalletTransactionAndSearchesAtMostEveryTenSeconds(): void
    {
        $payment = $this->payment(Config::WALLET, '0xabc');
        $fresh = $this->payment(Config::WALLET, '0xabc');
        $this->payments->method('getById')->willReturn($fresh);
        $this->transferMatcher->expects($this->once())->method('checkTransaction')->with($payment);
        $this->transferMatcher->expects($this->once())->method('scan')->with(3)->willReturn(true);

        $this->assertSame($fresh, $this->processor->refresh($payment));
        $this->processor->refresh($payment);
    }

    public function testPagePollOnAClosedPaymentDoesNothing(): void
    {
        $payment = $this->payment(Config::APP);
        $payment->setStatus(Payment::STATUS_PAID);
        $this->depositMatcher->expects($this->never())->method('run');
        $this->payments->expects($this->never())->method('getById');

        $this->assertSame($payment, $this->processor->refresh($payment));
    }

    /**
     * @param string $method
     * @param string|null $txHash
     * @return Payment
     */
    private function payment(string $method, ?string $txHash = null): Payment
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData([
            'id' => 9,
            'order_id' => 42,
            'method' => $method,
            'status' => Payment::STATUS_PENDING,
            'tx_hash' => $txHash,
        ]);

        return $payment;
    }
}
