<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model;

use JoePham\OkxPay\Model\AmountAllocator;
use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\OrderLocker;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentFactory;
use JoePham\OkxPay\Model\PaymentInfo;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\PaymentStarter;
use JoePham\OkxPay\Model\UsdConverter;
use JoePham\OkxPay\Model\XLayer\RpcClient;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentStarterTest extends TestCase
{
    /**
     * @var PaymentRepository|MockObject
     */
    private $payments;

    /**
     * @var AmountAllocator|MockObject
     */
    private $allocator;

    /**
     * @var RpcClient|MockObject
     */
    private $rpc;

    /**
     * @var OrderPaymentRepositoryInterface|MockObject
     */
    private $orderPaymentRepository;

    /**
     * @var array<string, mixed>
     */
    private array $info = [];

    private PaymentStarter $starter;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getPaymentWindow')->willReturn(30);
        $config->method('getCoinNames')->willReturnMap([
            [Config::APP, ['USDT']],
            [Config::WALLET, ['USD₮0', 'USDG']],
        ]);
        $this->payments = $this->createMock(PaymentRepository::class);
        $this->payments->method('save')->willReturnArgument(0);
        $factory = $this->createMock(PaymentFactory::class);
        $factory->method('create')->willReturnCallback(
            fn () => $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock()
        );
        $this->allocator = $this->createMock(AmountAllocator::class);
        $converter = $this->createMock(UsdConverter::class);
        $converter->method('toUsd')->willReturn('35.60');
        $locker = $this->createMock(OrderLocker::class);
        $locker->method('runAllocation')->willReturnCallback(fn (callable $callback) => $callback());
        $this->rpc = $this->createMock(RpcClient::class);
        $this->orderPaymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);

        $this->starter = new PaymentStarter(
            $config,
            $this->payments,
            $factory,
            $this->allocator,
            $converter,
            $locker,
            $this->rpc,
            $this->orderPaymentRepository
        );
    }

    public function testAppPaymentGetsAUniqueAmountAndAPageToken(): void
    {
        $this->allocator->expects($this->once())->method('allocate')->with(Config::APP, '35.60')->willReturn('35.6001');
        $this->rpc->expects($this->never())->method('getBlockNumber');
        $this->orderPaymentRepository->expects($this->once())->method('save');

        $payment = $this->starter->start($this->order(Config::APP));

        $this->assertSame(42, $payment->getOrderId());
        $this->assertSame(Payment::STATUS_PENDING, $payment->getStatus());
        $this->assertSame('35.6001', $payment->getAmount());
        $this->assertSame('35.60', $payment->getUsdAmount());
        $this->assertNull($payment->getStartBlock());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payment->getAccessToken());
        $this->assertEqualsWithDelta(time() + 1800, strtotime($payment->getExpiresAt() . ' UTC'), 5);
        $this->assertSame('35.6001 USDT', $this->info[PaymentInfo::AMOUNT]);
    }

    public function testWalletPaymentRemembersTheBlockItStartedAt(): void
    {
        $this->allocator->method('allocate')->willReturn('35.6002');
        $this->rpc->method('getBlockNumber')->willReturn(40000123);

        $payment = $this->starter->start($this->order(Config::WALLET));

        $this->assertSame('40000123', $payment->getStartBlock());
        $this->assertSame('35.6002 USD₮0/USDG', $this->info[PaymentInfo::AMOUNT]);
    }

    public function testExistingPaymentIsReused(): void
    {
        $existing = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $this->payments->method('getByOrderId')->willReturn($existing);
        $this->allocator->expects($this->never())->method('allocate');

        $this->assertSame($existing, $this->starter->start($this->order(Config::APP)));
    }

    public function testOtherPaymentMethodsAreRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->starter->start($this->order('checkmo'));
    }

    /**
     * @param string $method
     * @return Order
     */
    private function order(string $method): Order
    {
        $orderPayment = $this->createMock(OrderPayment::class);
        $orderPayment->method('getMethod')->willReturn($method);
        $orderPayment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) use ($orderPayment) {
            $this->info[$key] = $value;
            return $orderPayment;
        });
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getPayment')->willReturn($orderPayment);

        return $order;
    }
}
