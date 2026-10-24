<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Controller\Payment;

use JoePham\OkxPay\Controller\Payment\Status;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\PaymentRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
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
     * @var CheckoutSession|MockObject
     */
    private $checkoutSession;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    private int $code = 200;

    private Status $controller;

    protected function setUp(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('token')->willReturn('0123456789abcdef0123456789abcdef');
        $this->payments = $this->createMock(PaymentRepository::class);
        $this->processor = $this->createMock(PaymentProcessor::class);
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['getLastOrderId'])
            ->getMock();
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($json) {
            $this->code = $code;
            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(fn ($route) => 'https://shop.test/' . $route);

        $this->controller = new Status(
            $request,
            $this->payments,
            $this->processor,
            $this->checkoutSession,
            $jsonFactory,
            $url
        );
    }

    public function testUnknownTokenIsNotFound(): void
    {
        $this->processor->expects($this->never())->method('refresh');

        $this->controller->execute();

        $this->assertSame(404, $this->code);
    }

    public function testPaidPaymentRedirectsTheShopperWhoPlacedTheOrder(): void
    {
        $this->paymentWithStatus(Payment::STATUS_PAID);
        $this->checkoutSession->method('getLastOrderId')->willReturn('42');

        $this->controller->execute();

        $this->assertSame('paid', $this->data['status']);
        $this->assertSame('https://shop.test/checkout/onepage/success', $this->data['redirect']);
    }

    public function testPaidPaymentOpenedElsewhereDoesNotRedirect(): void
    {
        $this->paymentWithStatus(Payment::STATUS_PAID);
        $this->checkoutSession->method('getLastOrderId')->willReturn(null);

        $this->controller->execute();

        $this->assertNull($this->data['redirect']);
    }

    public function testOpenPaymentPastItsWindowShowsAsExpired(): void
    {
        $this->paymentWithStatus(Payment::STATUS_PENDING, gmdate('Y-m-d H:i:s', time() - 60));

        $this->controller->execute();

        $this->assertSame('expired', $this->data['status']);
        $this->assertSame(0, $this->data['secondsLeft']);
    }

    /**
     * @param string $status
     * @param string|null $expiresAt
     * @return void
     */
    private function paymentWithStatus(string $status, ?string $expiresAt = null): void
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData([
            'order_id' => 42,
            'status' => $status,
            'expires_at' => $expiresAt ?? gmdate('Y-m-d H:i:s', time() + 600),
        ]);
        $this->payments->method('getByToken')->willReturn($payment);
        $this->processor->expects($this->once())->method('refresh')->with($payment)->willReturn($payment);
    }
}
