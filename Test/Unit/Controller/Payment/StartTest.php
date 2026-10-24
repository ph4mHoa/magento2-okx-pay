<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Controller\Payment;

use JoePham\OkxPay\Controller\Payment\Start;
use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\PaymentStarter;
use JoePham\OkxPay\Model\XLayer\RpcException;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StartTest extends TestCase
{
    /**
     * @var CheckoutSession|MockObject
     */
    private $checkoutSession;

    /**
     * @var PaymentRepository|MockObject
     */
    private $payments;

    /**
     * @var PaymentStarter|MockObject
     */
    private $starter;

    /**
     * @var PaymentRegistrar|MockObject
     */
    private $registrar;

    /**
     * @var ManagerInterface|MockObject
     */
    private $messages;

    /**
     * @var array{0: string, 1: array}|null
     */
    private ?array $target = null;

    private Start $controller;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->payments = $this->createMock(PaymentRepository::class);
        $this->starter = $this->createMock(PaymentStarter::class);
        $this->registrar = $this->createMock(PaymentRegistrar::class);
        $this->messages = $this->createMock(ManagerInterface::class);
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->target = [$path, $params];
            return $redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $this->controller = new Start(
            $this->checkoutSession,
            $this->payments,
            $this->starter,
            $this->registrar,
            $redirectFactory,
            $this->messages,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testNewOrderGoesToItsPaymentPage(): void
    {
        $this->sessionOrder(Config::APP, Order::STATE_PENDING_PAYMENT);
        $this->starter->expects($this->once())->method('start')->willReturn($this->payment('a1b2'));

        $this->controller->execute();

        $this->assertSame(['joepham_okxpay/payment/view', ['token' => 'a1b2']], $this->target);
    }

    public function testStartedPaymentIsShownAgain(): void
    {
        $this->sessionOrder(Config::WALLET, Order::STATE_CANCELED);
        $this->payments->method('getByOrderId')->willReturn($this->payment('c3d4'));
        $this->starter->expects($this->never())->method('start');

        $this->controller->execute();

        $this->assertSame(['joepham_okxpay/payment/view', ['token' => 'c3d4']], $this->target);
    }

    public function testOrderOfAnotherMethodGoesToTheCart(): void
    {
        $this->sessionOrder('checkmo', Order::STATE_PENDING_PAYMENT);
        $this->starter->expects($this->never())->method('start');

        $this->controller->execute();

        $this->assertSame('checkout/cart', $this->target[0]);
    }

    public function testFailureCancelsTheOrderAndRestoresTheCart(): void
    {
        $this->sessionOrder(Config::APP, Order::STATE_PENDING_PAYMENT);
        $this->starter->method('start')->willThrowException(new LocalizedException(__('Too many payments.')));
        $this->registrar->expects($this->once())->method('cancelUnstarted')->with(42);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');
        $this->messages->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(fn ($message) => (string)$message === 'Too many payments.'));

        $this->controller->execute();

        $this->assertSame('checkout/cart', $this->target[0]);
    }

    public function testRpcDetailsAreNotShownToTheShopper(): void
    {
        $this->sessionOrder(Config::WALLET, Order::STATE_PENDING_PAYMENT);
        $this->starter->method('start')->willThrowException(new RpcException(__('X Layer RPC error -32000: secret')));
        $this->messages->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(fn ($message) => !str_contains((string)$message, 'secret')));

        $this->controller->execute();
    }

    /**
     * @param string $method
     * @param string $state
     * @return void
     */
    private function sessionOrder(string $method, string $state): void
    {
        $orderPayment = $this->createMock(OrderPayment::class);
        $orderPayment->method('getMethod')->willReturn($method);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($orderPayment);
        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
    }

    /**
     * @param string $token
     * @return Payment
     */
    private function payment(string $token): Payment
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData(['access_token' => $token]);

        return $payment;
    }
}
