<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\OrderLocker;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentInfo;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use JoePham\OkxPay\Model\Transfer;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PaymentRegistrarTest extends TestCase
{
    /**
     * @var PaymentRepository|MockObject
     */
    private $payments;

    /**
     * @var AdapterInterface|MockObject
     */
    private $connection;

    /**
     * @var OrderRepositoryInterface|MockObject
     */
    private $orderRepository;

    /**
     * @var OrderSender|MockObject
     */
    private $orderSender;

    /**
     * @var OrderStatusHistoryRepositoryInterface|MockObject
     */
    private $historyRepository;

    /**
     * @var Order|MockObject
     */
    private $order;

    /**
     * @var OrderPayment|MockObject
     */
    private $orderPayment;

    private Payment $payment;

    /**
     * @var string[]
     */
    private array $usedReferences = [];

    /**
     * @var array<string, mixed>
     */
    private array $info = [];

    /**
     * @var string[]
     */
    private array $comments = [];

    private string $orderState = Order::STATE_PENDING_PAYMENT;

    private PaymentRegistrar $registrar;

    protected function setUp(): void
    {
        $this->payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $this->payment->setData([
            'id' => 5,
            'order_id' => 42,
            'method' => Config::APP,
            'status' => Payment::STATUS_PENDING,
            'amount' => '35.60010000',
        ]);

        $this->payments = $this->createMock(PaymentRepository::class);
        $this->payments->method('getById')->willReturnCallback(fn () => $this->payment);
        $this->payments->method('isReferenceUsed')
            ->willReturnCallback(fn (string $reference) => in_array($reference, $this->usedReferences, true));
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(PaymentResource::class);
        $resource->method('getConnection')->willReturn($this->connection);

        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->orderPayment->method('getMethod')->willReturn(Config::APP);
        $this->orderPayment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) {
            $this->info[$key] = $value;
            return $this->orderPayment;
        });
        $this->order = $this->createMock(Order::class);
        $this->order->method('getId')->willReturn(42);
        $this->order->method('getIncrementId')->willReturn('000000042');
        $this->order->method('getState')->willReturnCallback(fn () => $this->orderState);
        $this->order->method('getPayment')->willReturn($this->orderPayment);
        $this->order->method('getBaseGrandTotal')->willReturn(35.6);
        $this->order->method('addCommentToStatusHistory')->willReturnCallback(function ($comment) {
            $this->comments[] = (string)$comment;
            return $this->createMock(History::class);
        });
        $orderFactory = $this->createMock(OrderFactory::class);
        $orderFactory->method('create')->willReturn($this->order);
        $locker = $this->createMock(OrderLocker::class);
        $locker->method('run')->willReturnCallback(fn (int $orderId, callable $callback) => $callback());

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->historyRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);

        $this->registrar = new PaymentRegistrar(
            $this->payments,
            $resource,
            $locker,
            $orderFactory,
            $this->createMock(OrderResource::class),
            $this->orderRepository,
            $this->orderSender,
            $this->historyRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testTransferForAWaitingOrderInvoicesIt(): void
    {
        $this->orderPayment->expects($this->once())->method('setTransactionId')->with('9001');
        $this->orderPayment->expects($this->once())->method('registerCaptureNotification')->with(35.6, true);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->payments->expects($this->once())->method('save')->with($this->payment);
        $this->orderRepository->expects($this->once())->method('save')->with($this->order);
        $this->orderSender->expects($this->once())->method('send')->with($this->order);

        $status = $this->registrar->registerTransfer($this->payment, $this->transfer());

        $this->assertSame(Payment::STATUS_PAID, $status);
        $this->assertSame('9001', $this->payment->getReference());
        $this->assertSame('USDT', $this->payment->getCoin());
        $this->assertSame('ale***@example.com', $this->payment->getPayer());
        $this->assertNotEmpty($this->payment->getPaidAt());
        $this->assertSame('35.6001 USDT', $this->info[PaymentInfo::RECEIVED]);
        $this->assertSame('9001', $this->info[PaymentInfo::REFERENCE]);
        $this->assertSame('ale***@example.com', $this->info[PaymentInfo::PAYER]);
        $this->assertStringContainsString('OKX payment received: 35.6001 USDT', $this->comments[0]);
    }

    public function testTransferAlreadyRecordedIsSkipped(): void
    {
        $this->usedReferences = ['9001'];
        $this->payments->expects($this->never())->method('save');
        $this->orderRepository->expects($this->never())->method('save');

        $this->assertNull($this->registrar->registerTransfer($this->payment, $this->transfer()));
    }

    public function testMoneyForAnExpiredPaymentIsFlaggedAsLate(): void
    {
        $this->payment->setStatus(Payment::STATUS_EXPIRED);
        $this->orderState = Order::STATE_CANCELED;
        $this->orderPayment->expects($this->never())->method('registerCaptureNotification');
        $this->orderRepository->expects($this->never())->method('save');
        $this->payments->expects($this->once())->method('save')->with($this->payment);
        $this->historyRepository->expects($this->once())->method('save');

        $this->assertSame(Payment::STATUS_LATE, $this->registrar->registerTransfer($this->payment, $this->transfer()));
        $this->assertSame('9001', $this->payment->getReference());
        $this->assertStringContainsString('arrived after the order stopped waiting', $this->comments[0]);
    }

    public function testOrderChangedByTheMerchantMeanwhileGetsALatePayment(): void
    {
        $this->orderState = Order::STATE_CANCELED;
        $this->orderPayment->expects($this->never())->method('registerCaptureNotification');

        $this->assertSame(Payment::STATUS_LATE, $this->registrar->registerTransfer($this->payment, $this->transfer()));
    }

    public function testPaidPaymentTakesNoSecondTransfer(): void
    {
        $this->payment->setStatus(Payment::STATUS_PAID);
        $this->payments->expects($this->never())->method('save');

        $this->assertNull($this->registrar->registerTransfer($this->payment, $this->transfer()));
    }

    public function testFailedSaveRollsBack(): void
    {
        $this->orderRepository->method('save')->willThrowException(new \RuntimeException('deadlock'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');
        $this->orderSender->expects($this->never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->registrar->registerTransfer($this->payment, $this->transfer());
    }

    public function testClosingAnOpenPaymentCancelsTheOrder(): void
    {
        $this->order->expects($this->once())->method('cancel');
        $this->orderRepository->expects($this->once())->method('save');

        $payment = $this->registrar->close($this->payment, Payment::STATUS_EXPIRED, __('Window ended.'));

        $this->assertSame(Payment::STATUS_EXPIRED, $payment->getStatus());
        $this->assertSame(['Window ended.'], $this->comments);
    }

    public function testClosingLeavesAnOrderTheMerchantAlreadyChanged(): void
    {
        $this->orderState = Order::STATE_PROCESSING;
        $this->order->expects($this->never())->method('cancel');
        $this->orderRepository->expects($this->never())->method('save');
        $this->payments->expects($this->once())->method('save');

        $this->assertSame(
            Payment::STATUS_CANCELED,
            $this->registrar->close($this->payment, Payment::STATUS_CANCELED, __('x'))->getStatus()
        );
    }

    public function testClosingAPaidPaymentChangesNothing(): void
    {
        $this->payment->setStatus(Payment::STATUS_PAID);
        $this->order->expects($this->never())->method('cancel');
        $this->payments->expects($this->never())->method('save');

        $this->assertSame(
            Payment::STATUS_PAID,
            $this->registrar->close($this->payment, Payment::STATUS_EXPIRED, __('x'))->getStatus()
        );
    }

    public function testWalletTransactionHashIsKeptInLowerCase(): void
    {
        $this->payment->setMethod(Config::WALLET);
        $this->payments->expects($this->once())->method('save');

        $this->registrar->submitTransaction($this->payment, '0xABC');

        $this->assertSame('0xabc', $this->payment->getTxHash());
    }

    public function testAppPaymentTakesNoTransactionHash(): void
    {
        $this->payments->expects($this->never())->method('save');

        $this->registrar->submitTransaction($this->payment, '0xabc');

        $this->assertNull($this->payment->getTxHash());
    }

    public function testOrderWithoutPaymentIsCancelled(): void
    {
        $this->payments->method('getByOrderId')->willReturn(null);
        $this->order->expects($this->once())->method('cancel');
        $this->orderRepository->expects($this->once())->method('save');

        $this->registrar->cancelUnstarted(42, __('Never opened.'));
    }

    public function testOrderWithAPaymentIsNotCancelledAsUnstarted(): void
    {
        $this->payments->method('getByOrderId')->willReturn($this->payment);
        $this->order->expects($this->never())->method('cancel');

        $this->registrar->cancelUnstarted(42, __('Never opened.'));
    }

    /**
     * @return Transfer
     */
    private function transfer(): Transfer
    {
        return new Transfer('USDT', '35.6001', '9001', '9001', 'ale***@example.com');
    }
}
