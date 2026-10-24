<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model\Okx;

use JoePham\OkxPay\Model\Okx\Signer;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase
{
    /**
     * Expected values were computed separately with Python's hmac module.
     */
    public function testSignatureMatchesAnIndependentHmac(): void
    {
        $signer = new Signer();

        $this->assertSame(
            'GEZ7GPSHJu2ODcmnxqH5wc+KxTZ2jaq8svlt8x6PRWU=',
            $signer->sign(
                '2026-10-25T09:08:57.715Z',
                'get',
                '/api/v5/asset/deposit-history?ccy=USDT&type=3&limit=100',
                '',
                'mock-secret'
            )
        );
        $this->assertSame(
            'HiZhvSfMtWJA3uUIVXV3a/bSXNPCWvYFXoGCVS8V4zY=',
            $signer->sign(
                '2020-12-08T09:08:57.715Z',
                'GET',
                '/api/v5/account/balance?ccy=BTC',
                '',
                '22582BD0CFF14C41EDBF1AB98506286D'
            )
        );
    }

    public function testBodyIsPartOfTheSignature(): void
    {
        $signer = new Signer();

        $this->assertNotSame(
            $signer->sign('2026-10-25T09:08:57.715Z', 'POST', '/api/v5/x', '', 'secret'),
            $signer->sign('2026-10-25T09:08:57.715Z', 'POST', '/api/v5/x', '{"a":1}', 'secret')
        );
    }

    public function testTimestampIsUtcWithMilliseconds(): void
    {
        $signer = new Signer();

        $this->assertSame('2026-10-24T19:08:57.715Z', $signer->timestamp(1792868937.7159));
        $this->assertSame('2026-10-24T19:08:57.000Z', $signer->timestamp(1792868937.0));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $signer->timestamp());
    }
}
