<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Gateway\Validator;

use JoePham\OkxPay\Gateway\Validator\AvailabilityValidator;
use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\UsdConverter;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Validator\Result;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use PHPUnit\Framework\TestCase;

class AvailabilityValidatorTest extends TestCase
{
    public function testUnconfiguredMethodIsHidden(): void
    {
        $this->assertFalse($this->validate(Config::APP, false, true, $this->quotePayment())->isValid());
        $this->assertFalse($this->validate(Config::WALLET, false, true, $this->quotePayment())->isValid());
    }

    public function testCartThatCanBePricedInDollarsIsAccepted(): void
    {
        $this->assertTrue($this->validate(Config::WALLET, true, true, $this->quotePayment())->isValid());
    }

    public function testCartWithoutADollarRateIsRefused(): void
    {
        $this->assertFalse($this->validate(Config::APP, true, false, $this->quotePayment())->isValid());
    }

    public function testOrderPaymentNeedsOnlyTheConfiguration(): void
    {
        $this->assertTrue(
            $this->validate(Config::APP, true, false, $this->createMock(InfoInterface::class))->isValid()
        );
    }

    /**
     * @param string $method
     * @param bool $configured
     * @param bool $convertible
     * @param InfoInterface $payment
     * @return Result
     */
    private function validate(string $method, bool $configured, bool $convertible, InfoInterface $payment): Result
    {
        $config = $this->createMock(Config::class);
        $config->method($method === Config::APP ? 'isAppConfigured' : 'isWalletConfigured')->willReturn($configured);
        $converter = $this->createMock(UsdConverter::class);
        $converter->method('canConvert')->with('EUR', 'EUR')->willReturn($convertible);
        $resultFactory = $this->createMock(ResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(fn (array $data) => new Result($data['isValid']));
        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        return (new AvailabilityValidator($resultFactory, $config, $converter, $method))
            ->validate(['payment' => $paymentDataObject]);
    }

    /**
     * @return QuotePayment
     */
    private function quotePayment(): QuotePayment
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getBaseCurrencyCode', 'getQuoteCurrencyCode'])
            ->getMock();
        $quote->method('getBaseCurrencyCode')->willReturn('EUR');
        $quote->method('getQuoteCurrencyCode')->willReturn('EUR');
        $payment = $this->createMock(QuotePayment::class);
        $payment->method('getQuote')->willReturn($quote);

        return $payment;
    }
}
