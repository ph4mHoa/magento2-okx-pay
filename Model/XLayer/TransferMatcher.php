<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\XLayer;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\Transfer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

/**
 * Finds the X Layer token transfers that pay OKX Wallet payments.
 *
 * A transfer pays a payment when it moves an accepted token to the receiving address, for exactly the payment's
 * amount, in a block mined after the payment started and buried under enough confirmations. Two paths lead
 * there: the transaction the shopper's wallet reported, and a scan of Transfer logs that remembers the last
 * block it read, so a payment sent from any wallet or exchange is found too.
 */
class TransferMatcher
{
    public const CURSOR_FLAG = 'joepham_okxpay_xlayer_cursor';

    /**
     * @param Config $config
     * @param RpcClient $rpcClient
     * @param PaymentRepository $payments
     * @param PaymentRegistrar $registrar
     * @param FlagManager $flagManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly RpcClient $rpcClient,
        private readonly PaymentRepository $payments,
        private readonly PaymentRegistrar $registrar,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check the transaction the shopper's wallet sent for this payment.
     *
     * @param Payment $payment
     * @return bool whether it was recorded
     * @throws LocalizedException
     */
    public function checkTransaction(Payment $payment): bool
    {
        $txHash = (string)$payment->getTxHash();
        if ($txHash === '' || !$payment->isPending()) {
            return false;
        }
        $receipt = $this->rpcClient->getTransactionReceipt($txHash);
        if ($receipt === null || Token::hexToDecimal((string)($receipt['status'] ?? '0')) !== '1') {
            return false;
        }
        $block = (int)Token::hexToDecimal((string)($receipt['blockNumber'] ?? '0'));
        if ($this->getSafeBlock($this->rpcClient->getBlockNumber()) < $block) {
            return false;
        }

        foreach ((array)($receipt['logs'] ?? []) as $log) {
            $match = is_array($log) ? $this->read($log) : null;
            if ($match !== null && $this->fits($payment, $match['units'], $block)) {
                return $this->registrar->registerTransfer($payment, $match['transfer']) !== null;
            }
        }

        return false;
    }

    /**
     * Read new confirmed blocks for transfers to the receiving address.
     *
     * @param int $maxRanges how many eth_getLogs calls this run may make
     * @return bool whether the scan reached the latest confirmed block
     * @throws LocalizedException when the RPC fails
     */
    public function scan(int $maxRanges): bool
    {
        $address = $this->config->getWalletAddress();
        $safeBlock = $this->getSafeBlock($this->rpcClient->getBlockNumber());
        $watched = $this->payments->getWatched(Config::WALLET);
        if ($watched === []) {
            $this->flagManager->saveFlag(self::CURSOR_FLAG, $safeBlock);
            return true;
        }

        $byUnits = [];
        $firstBlock = PHP_INT_MAX;
        foreach ($watched as $payment) {
            $byUnits[Token::toUnits((string)$payment->getAmount())] = $payment;
            $firstBlock = min($firstBlock, (int)$payment->getStartBlock());
        }
        $cursor = $this->flagManager->getFlagData(self::CURSOR_FLAG);
        $from = $cursor === null ? $firstBlock : max((int)$cursor + 1, $firstBlock);
        $tokens = array_map(
            static fn (string $code): string => Token::TOKENS[$code]['address'],
            $this->config->getWalletTokens()
        );
        $rangeSize = $this->config->getLogBlockRange();

        for ($i = 0; $i < $maxRanges && $from <= $safeBlock; $i++) {
            $to = min($from + $rangeSize - 1, $safeBlock);
            foreach ($this->rpcClient->getTransferLogs($from, $to, $tokens, $address) as $log) {
                $match = $this->read($log);
                $payment = $match !== null ? ($byUnits[$match['units']] ?? null) : null;
                if ($payment !== null && $this->fits($payment, $match['units'], $match['block'])) {
                    $this->register($payment, $match['transfer']);
                }
            }
            $this->flagManager->saveFlag(self::CURSOR_FLAG, $to);
            $from = $to + 1;
        }

        return $from > $safeBlock;
    }

    /**
     * Highest block with enough confirmations.
     *
     * @param int $latestBlock
     * @return int
     */
    private function getSafeBlock(int $latestBlock): int
    {
        return $latestBlock - $this->config->getConfirmations() + 1;
    }

    /**
     * Whether a transfer of $units mined in $block can pay $payment.
     *
     * @param Payment $payment
     * @param string $units
     * @param int $block
     * @return bool
     */
    private function fits(Payment $payment, string $units, int $block): bool
    {
        return $units === Token::toUnits((string)$payment->getAmount()) && $block >= (int)$payment->getStartBlock();
    }

    /**
     * A log as a transfer to the receiving address, or null when it is anything else.
     *
     * @param array<string,mixed> $log
     * @return array{units: string, block: int, transfer: Transfer}|null
     */
    private function read(array $log): ?array
    {
        $code = Token::codeOf((string)($log['address'] ?? ''));
        $topics = array_map(static fn ($topic): string => strtolower((string)$topic), (array)($log['topics'] ?? []));
        if ($code === null
            || !in_array($code, $this->config->getWalletTokens(), true)
            || !empty($log['removed'])
            || count($topics) !== 3
            || $topics[0] !== Token::TRANSFER_TOPIC
            || Token::topicToAddress($topics[2]) !== $this->config->getWalletAddress()
        ) {
            return null;
        }

        $units = Token::hexToDecimal((string)($log['data'] ?? '0'));
        $txHash = strtolower((string)($log['transactionHash'] ?? ''));

        return [
            'units' => $units,
            'block' => (int)Token::hexToDecimal((string)($log['blockNumber'] ?? '0')),
            'transfer' => new Transfer(
                $code,
                bcadd(Token::fromUnits($units), '0', 4),
                $txHash . ':' . Token::hexToDecimal((string)($log['logIndex'] ?? '0')),
                $txHash,
                Token::topicToAddress($topics[1])
            ),
        ];
    }

    /**
     * Record a transfer found by the scan; a failure is logged and the scan goes on.
     *
     * @param Payment $payment
     * @param Transfer $transfer
     * @return void
     */
    private function register(Payment $payment, Transfer $transfer): void
    {
        try {
            $this->registrar->registerTransfer($payment, $transfer);
        } catch (\Exception $e) {
            $this->logger->critical('Could not record an X Layer payment', [
                'order_id' => $payment->getOrderId(),
                'reference' => $transfer->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
