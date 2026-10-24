<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model\Okx;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Okx\Client;
use JoePham\OkxPay\Model\Okx\DepositMatcher;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\Transfer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DepositMatcherTest extends TestCase
{
    /** 2026-10-25 10:00:00 UTC in milliseconds. */
    private const CREATED_MS = 1792922400000;

    /**
     * @var Client|MockObject
     */
    private $client;

    /**
     * @var PaymentRepository|MockObject
     */
    private $payments;

    /**
     * @var PaymentRegistrar|MockObject
     */
    private $registrar;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var Payment[]
     */
    private array $watched = [];

    /**
     * @var string[]
     */
    private array $usedReferences = [];

    /**
     * @var array<int, array{0: Payment, 1: Transfer}>
     */
    private array $registered = [];

    private DepositMatcher $matcher;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getAppCoins')->willReturn(['USDT']);
        $this->client = $this->createMock(Client::class);
        $this->payments = $this->createMock(PaymentRepository::class);
        $this->payments->method('getWatched')->willReturnCallback(fn () => $this->watched);
        $this->payments->method('isReferenceUsed')
            ->willReturnCallback(fn (string $reference) => in_array($reference, $this->usedReferences, true));
        $this->registrar = $this->createMock(PaymentRegistrar::class);
        $this->registrar->method('registerTransfer')->willReturnCallback(function ($payment, $transfer) {
            $this->registered[] = [$payment, $transfer];
            return Payment::STATUS_PAID;
        });
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->matcher = new DepositMatcher($config, $this->client, $this->payments, $this->registrar, $this->logger);
    }

    public function testExactCreditedTransferPaysThePayment(): void
    {
        $payment = $this->payment(1, '35.60010000', '2026-10-25 10:00:00');
        $this->watched = [$payment];
        $this->client->expects($this->once())
            ->method('getInternalDeposits')
            ->with('USDT', self::CREATED_MS - 120000, null, 100)
            ->willReturn([$this->deposit('9001', '35.6001', self::CREATED_MS + 60000, 'ale***@example.com')]);

        $this->matcher->run();

        $this->assertCount(1, $this->registered);
        [$paid, $transfer] = $this->registered[0];
        $this->assertSame($payment, $paid);
        $this->assertSame('USDT', $transfer->coin);
        $this->assertSame('35.6001', $transfer->amount);
        $this->assertSame('9001', $transfer->reference);
        $this->assertSame('9001', $transfer->transactionId);
        $this->assertSame('ale***@example.com', $transfer->payer);
    }

    public function testTransfersThatDoNotFitAreIgnored(): void
    {
        $this->watched = [$this->payment(1, '35.60010000', '2026-10-25 10:00:00')];
        $this->usedReferences = ['9006'];
        $after = self::CREATED_MS + 1000;
        $this->client->method('getInternalDeposits')->willReturn([
            $this->deposit('9001', '35.60', $after),                       // order total without the unique digits
            $this->deposit('9002', '35.60011', $after),                    // finer than the 4 decimals asked for
            $this->deposit('9003', '35.6001', $after, '', '0'),            // not credited yet
            $this->deposit('9004', '35.6001', self::CREATED_MS - 300000),  // before the payment started
            $this->deposit('9005', '35.6001', $after, '', '2', 'USDC'),    // another coin
            $this->deposit('9006', '35.6001', $after),                     // already recorded
            $this->deposit('', '35.6001', $after),                         // no deposit ID
            $this->deposit('9007', '3e1', $after),                         // not a plain number
        ]);

        $this->matcher->run();

        $this->assertSame([], $this->registered);
    }

    public function testTrailingZerosStillMatchAndOldestTransferIsUsedFirst(): void
    {
        $this->watched = [$this->payment(1, '35.60010000', '2026-10-25 10:00:00')];
        $this->client->method('getInternalDeposits')->willReturn([
            $this->deposit('9012', '35.600100', self::CREATED_MS + 9000),
            $this->deposit('9011', '35.6001', self::CREATED_MS + 5000),
        ]);

        $this->matcher->run();

        $this->assertSame(['9011', '9012'], array_map(fn (array $r) => $r[1]->reference, $this->registered));
    }

    public function testNothingWatchedMakesNoApiCall(): void
    {
        $this->client->expects($this->never())->method('getInternalDeposits');

        $this->matcher->run();
    }

    public function testFullPagesArePagedBackward(): void
    {
        $this->watched = [$this->payment(1, '12.00020000', '2026-10-25 10:00:00')];
        $page = [];
        for ($i = 0; $i < 100; $i++) {
            $page[] = $this->deposit((string)(5000 + $i), '1.00', self::CREATED_MS + 900000 - $i * 1000);
        }
        $oldest = self::CREATED_MS + 900000 - 99 * 1000;
        $this->client->expects($this->exactly(2))
            ->method('getInternalDeposits')
            ->willReturnCallback(function ($coin, $since, $olderThan) use ($page, $oldest) {
                if ($olderThan === null) {
                    return $page;
                }
                $this->assertSame($oldest, $olderThan);
                return [$this->deposit('4000', '12.0002', self::CREATED_MS + 1000)];
            });

        $this->matcher->run();

        $this->assertSame('4000', $this->registered[0][1]->reference);
    }

    public function testFailureToRecordOneTransferDoesNotStopTheOthers(): void
    {
        $this->watched = [
            $this->payment(1, '35.60010000', '2026-10-25 10:00:00'),
            $this->payment(2, '20.00010000', '2026-10-25 10:00:00'),
        ];
        $this->client->method('getInternalDeposits')->willReturn([
            $this->deposit('9101', '35.6001', self::CREATED_MS + 1000),
            $this->deposit('9102', '20.0001', self::CREATED_MS + 2000),
        ]);
        $registrar = $this->createMock(PaymentRegistrar::class);
        $calls = 0;
        $registrar->method('registerTransfer')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('lock busy');
            }
            return Payment::STATUS_PAID;
        });
        $this->logger->expects($this->once())->method('critical');
        $config = $this->createMock(Config::class);
        $config->method('getAppCoins')->willReturn(['USDT']);

        (new DepositMatcher($config, $this->client, $this->payments, $registrar, $this->logger))->run();

        $this->assertSame(2, $calls);
    }

    /**
     * @param int $id
     * @param string $amount
     * @param string $createdAt
     * @return Payment
     */
    private function payment(int $id, string $amount, string $createdAt): Payment
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData([
            'id' => $id,
            'order_id' => 100 + $id,
            'method' => Config::APP,
            'status' => Payment::STATUS_PENDING,
            'amount' => $amount,
            'created_at' => $createdAt,
        ]);

        return $payment;
    }

    /**
     * @param string $depId
     * @param string $amount
     * @param int $ts
     * @param string $from
     * @param string $state
     * @param string $coin
     * @return array<string, string>
     */
    private function deposit(
        string $depId,
        string $amount,
        int $ts,
        string $from = 'sho***@example.com',
        string $state = '2',
        string $coin = 'USDT'
    ): array {
        return [
            'depId' => $depId,
            'amt' => $amount,
            'ccy' => $coin,
            'from' => $from,
            'state' => $state,
            'ts' => (string)$ts,
        ];
    }
}
