<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model;

use JoePham\OkxPay\Model\UsdConverter;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class UsdConverterTest extends TestCase
{
    public function testUsdOrdersUseTheOrderTotal(): void
    {
        $order = $this->order('USD', '35.6', 'EUR', '33.0');

        $this->assertSame('35.60', $this->converter([])->toUsd($order));
    }

    public function testUsdBaseCurrencyUsesTheBaseTotal(): void
    {
        $order = $this->order('CAD', '48.70', 'USD', '35.6');

        $this->assertSame('35.60', $this->converter([])->toUsd($order));
    }

    public function testOtherCurrenciesUseTheConfiguredRate(): void
    {
        $order = $this->order('EUR', '30.00', 'EUR', '30.00');

        $this->assertSame('32.40', $this->converter(['EUR' => 1.08])->toUsd($order));
    }

    public function testMissingRateIsAnError(): void
    {
        $this->expectException(LocalizedException::class);
        $this->converter([])->toUsd($this->order('VND', '900000', 'VND', '900000'));
    }

    public function testCanConvertNeedsUsdOrARate(): void
    {
        $converter = $this->converter(['EUR' => 1.08]);

        $this->assertTrue($converter->canConvert('CAD', 'USD'));
        $this->assertTrue($converter->canConvert('USD', 'CAD'));
        $this->assertTrue($converter->canConvert('EUR', 'GBP'));
        $this->assertFalse($converter->canConvert('VND', 'VND'));
    }

    /**
     * @param array<string, float> $rates
     * @return UsdConverter
     */
    private function converter(array $rates): UsdConverter
    {
        $factory = $this->createMock(CurrencyFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($rates) {
            $currency = $this->createMock(Currency::class);
            $code = '';
            $currency->method('load')->willReturnCallback(function ($value) use ($currency, &$code) {
                $code = $value;
                return $currency;
            });
            $currency->method('getAnyRate')->willReturnCallback(function () use ($rates, &$code) {
                return $rates[$code] ?? false;
            });
            return $currency;
        });

        return new UsdConverter($factory);
    }

    /**
     * @param string $orderCurrency
     * @param string $grandTotal
     * @param string $baseCurrency
     * @param string $baseGrandTotal
     * @return Order
     */
    private function order(string $orderCurrency, string $grandTotal, string $baseCurrency, string $baseGrandTotal): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn($orderCurrency);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getBaseCurrencyCode')->willReturn($baseCurrency);
        $order->method('getBaseGrandTotal')->willReturn($baseGrandTotal);

        return $order;
    }
}
