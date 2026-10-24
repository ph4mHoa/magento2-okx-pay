<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Controller\Payment;

use JoePham\OkxPay\Controller\Payment\Cancel;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CancelTest extends TestCase
{
    /**
     * @var PaymentRepository|MockObject
     */
    private $payments;

    /**
     * @var PaymentProcessor|MockObject
     */
    private $processor;

    /**
     * @var PaymentRegistrar|MockObject
     */
    private $registrar;

    /**
     * @var CheckoutSession|MockObject
     */
    private $checkoutSession;

    /**
     * @var array{0: string, 1: array}|null
     */
    private ?array $target = null;

    private Cancel $controller;

    protected function setUp(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn('0123456789abcdef0123456789abcdef');
        $this->payments = $this->createMock(PaymentRepository::class);
        $this->processor = $this->createMock(PaymentProcessor::class);
        $this->processor->method('refresh')->willReturnArgument(0);
        $this->registrar = $this->createMock(PaymentRegistrar::class);
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['restoreQuote'])
            ->addMethods(['getLastOrderId'])
            ->getMock();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->target = [$path, $params];
            return $redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $this->controller = new Cancel(
            $request,
            $this->payments,
            $this->processor,
            $this->registrar,
            $this->checkoutSession,
            $redirectFactory,
            $this->createMock(ManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testCancelledPaymentRestoresTheShoppersCart(): void
    {
        $payment = $this->payment();
        $this->registrar->expects($this->once())
            ->method('close')
            ->with($payment, Payment::STATUS_CANCELED, $this->anything())
            ->willReturnCallback(fn (Payment $p) => $p->setStatus(Payment::STATUS_CANCELED));
        $this->checkoutSession->method('getLastOrderId')->willReturn('42');
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $this->controller->execute();

        $this->assertSame('checkout/cart', $this->target[0]);
    }

    public function testCancelFromAnotherBrowserLeavesItsCartAlone(): void
    {
        $this->payment();
        $this->registrar->method('close')->willReturnCallback(fn (Payment $p) => $p->setStatus(Payment::STATUS_CANCELED));
        $this->checkoutSession->method('getLastOrderId')->willReturn(null);
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->controller->execute();

        $this->assertSame('checkout/cart', $this->target[0]);
    }

    public function testPaymentThatArrivedMeanwhileIsNotCancelled(): void
    {
        $this->payment();
        $this->registrar->method('close')->willReturnCallback(fn (Payment $p) => $p->setStatus(Payment::STATUS_PAID));
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->controller->execute();

        $this->assertSame(['joepham_okxpay/payment/view', ['token' => 'a1b2']], $this->target);
    }

    /**
     * @return Payment
     */
    private function payment(): Payment
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData(['order_id' => 42, 'status' => Payment::STATUS_PENDING, 'access_token' => 'a1b2']);
        $this->payments->method('getByToken')->willReturn($payment);

        return $payment;
    }
}
