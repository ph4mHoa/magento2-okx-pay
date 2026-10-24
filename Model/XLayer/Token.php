<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\XLayer;

/**
 * Stablecoins accepted on X Layer (chain ID 196). All have 6 decimals.
 */
class Token
{
    public const CHAIN_ID = 196;
    public const DECIMALS = 6;

    // phpcs:disable PHPCompatibility.Numbers.RemovedHexadecimalNumericStrings -- addresses and hashes, not numbers
    /** Contract addresses as read from the chain (symbol(), decimals()) in October 2026. */
    public const TOKENS = [
        'USDT0' => ['symbol' => 'USD₮0', 'address' => '0x779ded0c9e1022225f8e0630b35a9b54be713736'],
        'USDT' => ['symbol' => 'USDT', 'address' => '0x1e4a5963abfd975d8c9021ce480b42188849d41d'],
        'USDG' => ['symbol' => 'USDG', 'address' => '0x4ae46a509f6b1d9056937ba4500cb143933d2dc8'],
        'USDC' => ['symbol' => 'USDC', 'address' => '0x74b7f16337b8972027f6196a17a631ac6de26d22'],
    ];

    /** keccak256("Transfer(address,address,uint256)") */
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    /** transfer(address,uint256) */
    public const TRANSFER_SELECTOR = '0xa9059cbb';
    // phpcs:enable

    /**
     * Token code for a contract address, or null.
     *
     * @param string $address
     * @return string|null
     */
    public static function codeOf(string $address): ?string
    {
        foreach (self::TOKENS as $code => $token) {
            if (strcasecmp($token['address'], $address) === 0) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Token amount ("12.3456") in base units ("12345600").
     *
     * @param string $amount
     * @return string
     */
    public static function toUnits(string $amount): string
    {
        return bcmul($amount, bcpow('10', (string)self::DECIMALS), 0);
    }

    /**
     * Base units ("12345600") as a token amount ("12.34560000").
     *
     * @param string $units
     * @return string
     */
    public static function fromUnits(string $units): string
    {
        return bcdiv($units, bcpow('10', (string)self::DECIMALS), 8);
    }

    /**
     * Unsigned hex quantity ("0x...", any length) as a decimal string.
     *
     * @param string $hex
     * @return string
     */
    public static function hexToDecimal(string $hex): string
    {
        $hex = ltrim(strtolower(str_starts_with($hex, '0x') ? substr($hex, 2) : $hex), '0');
        $decimal = '0';
        foreach (str_split($hex !== '' ? $hex : '0') as $digit) {
            $decimal = bcadd(bcmul($decimal, '16'), (string)hexdec($digit));
        }

        return $decimal;
    }

    /**
     * Unsigned decimal string as lower-case hex without 0x.
     *
     * @param string $decimal
     * @return string
     */
    public static function decimalToHex(string $decimal): string
    {
        $hex = '';
        while (bccomp($decimal, '0') > 0) {
            $hex = dechex((int)bcmod($decimal, '16')) . $hex;
            $decimal = bcdiv($decimal, '16', 0);
        }

        return $hex !== '' ? $hex : '0';
    }

    /**
     * Call data of transfer($to, $units).
     *
     * @param string $to
     * @param string $units
     * @return string
     */
    public static function encodeTransfer(string $to, string $units): string
    {
        return self::TRANSFER_SELECTOR
            . substr(self::addressToTopic($to), 2)
            . str_pad(self::decimalToHex($units), 64, '0', STR_PAD_LEFT);
    }

    /**
     * 32-byte log topic holding an address, as a 0x address.
     *
     * @param string $topic
     * @return string
     */
    public static function topicToAddress(string $topic): string
    {
        return '0x' . substr(strtolower($topic), -40);
    }

    /**
     * Address as a 32-byte log topic.
     *
     * @param string $address
     * @return string
     */
    public static function addressToTopic(string $address): string
    {
        return '0x' . str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
    }
}
