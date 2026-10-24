<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model\XLayer;

use JoePham\OkxPay\Model\XLayer\Token;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    public function testAmountsConvertToSixDecimalUnitsAndBack(): void
    {
        $this->assertSame('35603700', Token::toUnits('35.6037'));
        $this->assertSame('35603700', Token::toUnits('35.60370000'));
        $this->assertSame('1', Token::toUnits('0.000001'));
        $this->assertSame('35.60370000', Token::fromUnits('35603700'));
    }

    public function testHexQuantitiesOfAnySizeConvertToDecimal(): void
    {
        $this->assertSame('0', Token::hexToDecimal('0x'));
        $this->assertSame('0', Token::hexToDecimal('0x0'));
        $this->assertSame('35603700', Token::hexToDecimal('0x00000000000000000000000000000000000000000000000000000000021f44f4'));
        $this->assertSame(
            '115792089237316195423570985008687907853269984665640564039457584007913129639935',
            Token::hexToDecimal('0x' . str_repeat('f', 64))
        );
    }

    public function testDecimalsConvertToHex(): void
    {
        $this->assertSame('0', Token::decimalToHex('0'));
        $this->assertSame('21f44f4', Token::decimalToHex('35603700'));
        $this->assertSame(
            str_repeat('f', 64),
            Token::decimalToHex('115792089237316195423570985008687907853269984665640564039457584007913129639935')
        );
    }

    public function testTransferCallDataIsAbiEncoded(): void
    {
        $this->assertSame(
            '0xa9059cbb'
            . '0000000000000000000000009a3f0c2e5b7d41c8e6f2a1b0d4c7e9f3a5b8c1d2'
            . '00000000000000000000000000000000000000000000000000000000021f44f4',
            Token::encodeTransfer('0x9A3F0C2E5B7D41C8E6F2A1B0D4C7E9F3A5B8C1D2', '35603700')
        );
    }

    public function testTopicsAndAddressesConvertBothWays(): void
    {
        $topic = Token::addressToTopic('0x9A3F0C2E5B7D41C8E6F2A1B0D4C7E9F3A5B8C1D2');

        $this->assertSame('0x0000000000000000000000009a3f0c2e5b7d41c8e6f2a1b0d4c7e9f3a5b8c1d2', $topic);
        $this->assertSame('0x9a3f0c2e5b7d41c8e6f2a1b0d4c7e9f3a5b8c1d2', Token::topicToAddress($topic));
    }

    public function testTokenCodesAreFoundByContractAddressInAnyCase(): void
    {
        $this->assertSame('USDG', Token::codeOf('0x4AE46A509F6B1D9056937BA4500CB143933D2DC8'));
        $this->assertSame('USDT0', Token::codeOf('0x779ded0c9e1022225f8e0630b35a9b54be713736'));
        $this->assertNull(Token::codeOf('0x' . str_repeat('12', 20)));
    }
}
