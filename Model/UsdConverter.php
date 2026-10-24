<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * Order totals in US dollars, which the stablecoins are priced at one to one.
 */
class UsdConverter
{
    private const USD = 'USD';

    /**
     * @param CurrencyFactory $currencyFactory
     */
    public function __construct(
        private readonly CurrencyFactory $currencyFactory
    ) {
    }

    /**
     * Order total in US dollars, two decimals.
     *
     * @param Order $order
     * @return string
     * @throws LocalizedException when the store has no rate to US dollars
     */
    public function toUsd(Order $order): string
    {
        if ($order->getOrderCurrencyCode() === self::USD) {
            return $this->money((float)$order->getGrandTotal());
        }
        if ($order->getBaseCurrencyCode() === self::USD) {
            return $this->money((float)$order->getBaseGrandTotal());
        }

        $rate = $this->getRate((string)$order->getBaseCurrencyCode());
        if ($rate === null) {
            throw new LocalizedException(
                __('There is no exchange rate from %1 to US dollars.', $order->getBaseCurrencyCode())
            );
        }

        return $this->money((float)$order->getBaseGrandTotal() * $rate);
    }

    /**
     * Whether totals in these currencies can be priced in US dollars.
     *
     * @param string $baseCurrency
     * @param string $quoteCurrency
     * @return bool
     */
    public function canConvert(string $baseCurrency, string $quoteCurrency): bool
    {
        return $quoteCurrency === self::USD || $baseCurrency === self::USD || $this->getRate($baseCurrency) !== null;
    }

    /**
     * Configured rate from a currency to US dollars.
     *
     * @param string $currency
     * @return float|null
     */
    private function getRate(string $currency): ?float
    {
        $rate = $this->currencyFactory->create()->load($currency)->getAnyRate(self::USD);

        return $rate ? (float)$rate : null;
    }

    /**
     * Two-decimal string.
     *
     * @param float $value
     * @return string
     */
    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
