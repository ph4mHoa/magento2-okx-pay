<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model\Config\Backend;

use JoePham\OkxPay\Model\Config\Backend\WalletAddress;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class WalletAddressTest extends TestCase
{
    public function testAddressIsStoredInLowerCase(): void
    {
        $backend = $this->backend(' 0x9A3F0C2E5B7D41C8E6F2A1B0D4C7E9F3A5B8C1D2 ');

        $backend->beforeSave();

        $this->assertSame('0x9a3f0c2e5b7d41c8e6f2a1b0d4c7e9f3a5b8c1d2', $backend->getValue());
    }

    public function testEmptyAddressIsAllowed(): void
    {
        $backend = $this->backend('');

        $backend->beforeSave();

        $this->assertSame('', $backend->getValue());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'too short' => ['0x1234'],
            'not hex' => ['0x' . str_repeat('g', 40)],
            'no prefix' => [str_repeat('a', 40)],
            'private key' => ['0x' . str_repeat('a', 64)],
            'token contract' => ['0x4ae46a509f6b1d9056937ba4500cb143933d2dc8'],
        ];
    }

    /**
     * @dataProvider invalidAddresses
     * @param string $address
     * @return void
     */
    public function testInvalidAddressIsRefused(string $address): void
    {
        $this->expectException(LocalizedException::class);
        $this->backend($address)->beforeSave();
    }

    /**
     * @param string $value
     * @return WalletAddress
     */
    private function backend(string $value): WalletAddress
    {
        $backend = (new ObjectManager($this))->getObject(WalletAddress::class);
        $backend->setValue($value);

        return $backend;
    }
}
