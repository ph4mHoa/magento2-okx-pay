<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model\XLayer;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\Transfer;
use JoePham\OkxPay\Model\XLayer\RpcClient;
use JoePham\OkxPay\Model\XLayer\Token;
use JoePham\OkxPay\Model\XLayer\TransferMatcher;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TransferMatcherTest extends TestCase
{
    private const MERCHANT = '0x9a3f0c2e5b7d41c8e6f2a1b0d4c7e9f3a5b8c1d2';
    private const SHOPPER = '0x52908400098527886e0f7030069857d2e4169ee7';
    private const USDG = '0x4ae46a509f6b1d9056937ba4500cb143933d2dc8';
    private const USDT0 = '0x779ded0c9e1022225f8e0630b35a9b54be713736';
    private const HASH = '0x92e1797671f8511208bb095b1441f7c3dd8daddbee6c11c626849fbd0d367d9c';

    /**
     * @var RpcClient|MockObject
     */
    private $rpc;

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

    private mixed $cursor = null;

    /**
     * @var array<int, array{0: int, 1: int}>
     */
    private array $ranges = [];

    /**
     * @var array<int, array<int, array<string, mixed>>> logs by range start
     */
    private array $logs = [];

    /**
     * @var array<int, array{0: Payment, 1: Transfer}>
     */
    private array $registered = [];

    private TransferMatcher $matcher;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getWalletAddress')->willReturn(self::MERCHANT);
        $config->method('getWalletTokens')->willReturn(['USDT0', 'USDG']);
        $config->method('getConfirmations')->willReturn(3);
        $config->method('getLogBlockRange')->willReturn(100);

        $this->rpc = $this->createMock(RpcClient::class);
        $this->rpc->method('getTransferLogs')->willReturnCallback(
            function (int $from, int $to, array $tokens, string $recipient) {
                $this->assertSame([self::USDT0, self::USDG], $tokens);
                $this->assertSame(self::MERCHANT, $recipient);
                $this->ranges[] = [$from, $to];
                return $this->logs[$from] ?? [];
            }
        );
        $payments = $this->createMock(PaymentRepository::class);
        $payments->method('getWatched')->willReturnCallback(fn () => $this->watched);
        $this->registrar = $this->createMock(PaymentRegistrar::class);
        $this->registrar->method('registerTransfer')->willReturnCallback(function ($payment, $transfer) {
            $this->registered[] = [$payment, $transfer];
            return Payment::STATUS_PAID;
        });
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->cursor);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $value) {
            $this->cursor = $value;
            return true;
        });
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->matcher = new TransferMatcher($config, $this->rpc, $payments, $this->registrar, $flags, $this->logger);
    }

    public function testNothingWatchedMovesTheCursorToTheLatestConfirmedBlock(): void
    {
        $this->rpc->method('getBlockNumber')->willReturn(1000);

        $this->assertTrue($this->matcher->scan(10));
        $this->assertSame(998, $this->cursor);
        $this->assertSame([], $this->ranges);
    }

    public function testFirstScanStartsAtTheOldestPaymentInRangesOfAtMostOneHundredBlocks(): void
    {
        $this->rpc->method('getBlockNumber')->willReturn(1000);
        $this->watched = [$this->payment(1, '35.60010000', 700), $this->payment(2, '12.00010000', 950)];

        $this->assertFalse($this->matcher->scan(2));
        $this->assertSame([[700, 799], [800, 899]], $this->ranges);
        $this->assertSame(899, $this->cursor);

        $this->assertTrue($this->matcher->scan(10));
        $this->assertSame([[900, 998]], array_slice($this->ranges, 2));
        $this->assertSame(998, $this->cursor);
    }

    public function testScanGoesOnFromTheCursor(): void
    {
        $this->rpc->method('getBlockNumber')->willReturn(1000);
        $this->watched = [$this->payment(1, '35.60010000', 700)];
        $this->cursor = 950;

        $this->assertTrue($this->matcher->scan(10));
        $this->assertSame([[951, 998]], $this->ranges);
    }

    public function testMatchingTransferIsRecorded(): void
    {
        $this->rpc->method('getBlockNumber')->willReturn(1000);
        $payment = $this->payment(1, '35.60010000', 900);
        $this->watched = [$payment];
        $this->cursor = 899;
        $this->logs[900] = [$this->log(self::USDG, '35600100', 905, 3)];

        $this->matcher->scan(10);

        $this->assertCount(1, $this->registered);
        [$paid, $transfer] = $this->registered[0];
        $this->assertSame($payment, $paid);
        $this->assertSame('USDG', $transfer->coin);
        $this->assertSame('35.6001', $transfer->amount);
        $this->assertSame(self::HASH . ':3', $transfer->reference);
        $this->assertSame(self::HASH, $transfer->transactionId);
        $this->assertSame(self::SHOPPER, $transfer->payer);
    }

    public function testTransfersThatDoNotFitAreIgnored(): void
    {
        $this->rpc->method('getBlockNumber')->willReturn(1000);
        // An older payment keeps the scan reading from block 850, before this payment started at 900.
        $this->watched = [$this->payment(2, '99.00010000', 850), $this->payment(1, '35.60010000', 900)];
        $this->cursor = 849;
        $this->logs[850] = [
            $this->log(self::USDG, '35600100', 880),                         // mined before the payment started
            $this->log('0x' . str_repeat('12', 20), '35600100', 905),       // unknown token
            $this->log('0x74b7f16337b8972027f6196a17a631ac6de26d22', '35600100', 905), // USDC: not accepted here
            $this->log(self::USDG, '35600000', 905),                         // plain order total
            $this->log(self::USDG, '35600101', 905),                         // finer than 4 decimals
            ['removed' => true] + $this->log(self::USDG, '35600100', 905),   // reorganised away
            $this->log(self::USDG, '35600100', 905, 0, '0x' . str_repeat('ab', 20)), // to someone else
        ];

        $this->matcher->scan(10);

        $this->assertSame([[850, 949], [950, 998]], $this->ranges);
        $this->assertSame([], $this->registered);
    }

    public function testRecordingFailureIsLoggedAndTheScanGoesOn(): void
    {
        $this->rpc->method('getBlockNumber')->willReturn(1000);
        $this->watched = [$this->payment(1, '35.60010000', 900)];
        $this->cursor = 899;
        $this->logs[900] = [$this->log(self::USDG, '35600100', 905)];
        $registrar = $this->createMock(PaymentRegistrar::class);
        $registrar->method('registerTransfer')->willThrowException(new \RuntimeException('lock busy'));
        $this->logger->expects($this->once())->method('critical');
        $config = $this->createMock(Config::class);
        $config->method('getWalletAddress')->willReturn(self::MERCHANT);
        $config->method('getWalletTokens')->willReturn(['USDT0', 'USDG']);
        $config->method('getConfirmations')->willReturn(3);
        $config->method('getLogBlockRange')->willReturn(100);
        $payments = $this->createMock(PaymentRepository::class);
        $payments->method('getWatched')->willReturn($this->watched);
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturn(899);
        $flags->expects($this->once())->method('saveFlag')->with(TransferMatcher::CURSOR_FLAG, 998);

        $matcher = new TransferMatcher($config, $this->rpc, $payments, $registrar, $flags, $this->logger);

        $this->assertTrue($matcher->scan(10));
    }

    public function testSubmittedTransactionPaysOnceConfirmed(): void
    {
        $payment = $this->payment(1, '35.60010000', 900, self::HASH);
        $this->rpc->method('getTransactionReceipt')->with(self::HASH)->willReturn([
            'status' => '0x1',
            'blockNumber' => '0x' . dechex(905),
            'logs' => [$this->log(self::USDT0, '1', 905, 0), $this->log(self::USDT0, '35600100', 905, 1)],
        ]);
        $this->rpc->method('getBlockNumber')->willReturn(907);

        $this->assertTrue($this->matcher->checkTransaction($payment));
        $this->assertSame(self::HASH . ':1', $this->registered[0][1]->reference);
        $this->assertSame('USDT0', $this->registered[0][1]->coin);
    }

    public function testSubmittedTransactionWaitsForConfirmations(): void
    {
        $payment = $this->payment(1, '35.60010000', 900, self::HASH);
        $this->rpc->method('getTransactionReceipt')->willReturn([
            'status' => '0x1',
            'blockNumber' => '0x' . dechex(905),
            'logs' => [$this->log(self::USDT0, '35600100', 905)],
        ]);
        $this->rpc->method('getBlockNumber')->willReturn(906);

        $this->assertFalse($this->matcher->checkTransaction($payment));
        $this->assertSame([], $this->registered);
    }

    public function testRevertedOrUnminedTransactionPaysNothing(): void
    {
        $payment = $this->payment(1, '35.60010000', 900, self::HASH);
        $this->rpc->method('getTransactionReceipt')->willReturnOnConsecutiveCalls(
            null,
            ['status' => '0x0', 'blockNumber' => '0x' . dechex(905), 'logs' => []]
        );
        $this->rpc->method('getBlockNumber')->willReturn(1000);

        $this->assertFalse($this->matcher->checkTransaction($payment));
        $this->assertFalse($this->matcher->checkTransaction($payment));
        $this->assertSame([], $this->registered);
    }

    public function testPaymentWithoutTransactionIsNotChecked(): void
    {
        $this->rpc->expects($this->never())->method('getTransactionReceipt');

        $this->assertFalse($this->matcher->checkTransaction($this->payment(1, '35.60010000', 900)));
    }

    /**
     * @param int $id
     * @param string $amount
     * @param int $startBlock
     * @param string|null $txHash
     * @return Payment
     */
    private function payment(int $id, string $amount, int $startBlock, ?string $txHash = null): Payment
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData([
            'id' => $id,
            'order_id' => 100 + $id,
            'method' => Config::WALLET,
            'status' => Payment::STATUS_PENDING,
            'amount' => $amount,
            'start_block' => (string)$startBlock,
            'tx_hash' => $txHash,
        ]);

        return $payment;
    }

    /**
     * @param string $token
     * @param string $units
     * @param int $block
     * @param int $logIndex
     * @param string $to
     * @return array<string, mixed>
     */
    private function log(string $token, string $units, int $block, int $logIndex = 0, string $to = self::MERCHANT): array
    {
        return [
            'address' => $token,
            'topics' => [Token::TRANSFER_TOPIC, Token::addressToTopic(self::SHOPPER), Token::addressToTopic($to)],
            'data' => '0x' . str_pad(Token::decimalToHex($units), 64, '0', STR_PAD_LEFT),
            'blockNumber' => '0x' . dechex($block),
            'transactionHash' => self::HASH,
            'logIndex' => '0x' . dechex($logIndex),
            'removed' => false,
        ];
    }
}
