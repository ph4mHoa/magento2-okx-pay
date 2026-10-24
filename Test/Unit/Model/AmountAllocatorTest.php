<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model;

use JoePham\OkxPay\Model\AmountAllocator;
use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class AmountAllocatorTest extends TestCase
{
    public function testFirstFreeAmountAboveTheTotalIsTaken(): void
    {
        $resource = $this->createMock(PaymentResource::class);
        $resource->expects($this->once())
            ->method('getReservedAmounts')
            ->with(
                'joepham_okx_app',
                '35.6001',
                '35.6999',
                $this->callback(function (string $since): bool {
                    return abs(strtotime($since . ' UTC') - (time() - AmountAllocator::RESERVE_SECONDS)) < 5;
                })
            )
            ->willReturn(['35.60010000', '35.60020000', '35.60040000']);

        $this->assertSame('35.6003', (new AmountAllocator($resource))->allocate('joepham_okx_app', '35.60'));
    }

    public function testNothingReservedGivesTheSmallestAddition(): void
    {
        $resource = $this->createMock(PaymentResource::class);
        $resource->method('getReservedAmounts')->willReturn([]);

        $this->assertSame('0.0101', (new AmountAllocator($resource))->allocate('joepham_okx_wallet', '0.01'));
    }

    public function testAllAmountsTakenIsAnError(): void
    {
        $taken = [];
        for ($step = 1; $step <= 999; $step++) {
            $taken[] = bcadd('10.00', bcmul('0.0001', (string)$step, 4), 8);
        }
        $resource = $this->createMock(PaymentResource::class);
        $resource->method('getReservedAmounts')->willReturn($taken);

        $this->expectException(LocalizedException::class);
        (new AmountAllocator($resource))->allocate('joepham_okx_app', '10.00');
    }
}
