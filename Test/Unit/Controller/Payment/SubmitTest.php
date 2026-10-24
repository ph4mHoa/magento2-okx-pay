<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Controller\Payment;

use JoePham\OkxPay\Controller\Payment\Submit;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubmitTest extends TestCase
{
    private const HASH = '0x92E1797671F8511208BB095B1441F7C3DD8DADDBEE6C11C626849FBD0D367D9C';

    /**
     * @var PaymentRegistrar|MockObject
     */
    private $registrar;

    /**
     * @var PaymentProcessor|MockObject
     */
    private $processor;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    private int $code = 200;

    public function testValidHashIsRecordedAndCheckedAtOnce(): void
    {
        $payment = $this->payment();
        $this->registrar->expects($this->once())
            ->method('submitTransaction')
            ->with($payment, strtolower(self::HASH))
            ->willReturn($payment);
        $this->processor->expects($this->once())->method('refresh')->with($payment)->willReturn($payment);

        $this->controller($payment, self::HASH)->execute();

        $this->assertSame(200, $this->code);
        $this->assertSame('pending', $this->data['status']);
    }

    public function testMalformedHashIsRefused(): void
    {
        $payment = $this->payment();
        $controller = $this->controller($payment, '0x1234');
        $this->registrar->expects($this->never())->method('submitTransaction');

        $controller->execute();

        $this->assertSame(400, $this->code);
    }

    public function testUnknownTokenIsRefused(): void
    {
        $controller = $this->controller(null, self::HASH);
        $this->registrar->expects($this->never())->method('submitTransaction');

        $controller->execute();

        $this->assertSame(400, $this->code);
    }

    /**
     * @param Payment|null $payment
     * @param string $txHash
     * @return Submit
     */
    private function controller(?Payment $payment, string $txHash): Submit
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([
            ['token', null, '0123456789abcdef0123456789abcdef'],
            ['tx_hash', null, $txHash],
        ]);
        $payments = $this->createMock(PaymentRepository::class);
        $payments->method('getByToken')->willReturn($payment);
        $this->registrar ??= $this->createMock(PaymentRegistrar::class);
        $this->processor ??= $this->createMock(PaymentProcessor::class);
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

        return new Submit(
            $request,
            $payments,
            $this->registrar,
            $this->processor,
            $jsonFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return Payment
     */
    private function payment(): Payment
    {
        $this->registrar = $this->createMock(PaymentRegistrar::class);
        $this->processor = $this->createMock(PaymentProcessor::class);
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData([
            'order_id' => 42,
            'status' => Payment::STATUS_PENDING,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);

        return $payment;
    }
}
