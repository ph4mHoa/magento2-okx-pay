<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\Okx;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentRegistrar;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\Transfer;
use Psr\Log\LoggerInterface;

/**
 * Finds the OKX internal transfers that pay OKX app payments.
 *
 * A deposit pays a payment when it is an internal transfer of an accepted coin for exactly the payment's
 * amount, credited, and made after the payment started. Its deposit ID is the reference, so it is used once.
 */
class DepositMatcher
{
    /** 1: credited, 2: successful. */
    private const RECEIVED_STATES = ['1', '2'];
    private const MAX_PAGES = 5;
    /** Seconds the OKX clock may be ahead of or behind this server. */
    private const CLOCK_SKEW = 120;

    /**
     * @param Config $config
     * @param Client $client
     * @param PaymentRepository $payments
     * @param PaymentRegistrar $registrar
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly PaymentRepository $payments,
        private readonly PaymentRegistrar $registrar,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Read the recent internal transfers and record those that pay a watched payment.
     *
     * @return void
     * @throws ApiException
     */
    public function run(): void
    {
        $byAmount = [];
        $since = PHP_INT_MAX;
        foreach ($this->payments->getWatched(Config::APP) as $payment) {
            $byAmount[$this->normalize((string)$payment->getAmount())] = $payment;
            $since = min($since, $this->startedAt($payment));
        }
        if ($byAmount === []) {
            return;
        }

        foreach ($this->config->getAppCoins() as $coin) {
            foreach ($this->fetch($coin, $since) as $deposit) {
                $amount = $this->normalize((string)($deposit['amt'] ?? ''));
                $payment = $byAmount[$amount] ?? null;
                $depositId = (string)($deposit['depId'] ?? '');
                if ($payment === null
                    || $depositId === ''
                    || strtoupper((string)($deposit['ccy'] ?? '')) !== $coin
                    || !in_array((string)($deposit['state'] ?? ''), self::RECEIVED_STATES, true)
                    || (int)($deposit['ts'] ?? 0) < $this->startedAt($payment)
                    || $this->payments->isReferenceUsed($depositId)
                ) {
                    continue;
                }

                $this->register($payment, new Transfer(
                    $coin,
                    $amount,
                    $depositId,
                    $depositId,
                    (string)($deposit['from'] ?? '')
                ));
            }
        }
    }

    /**
     * Internal transfers of a coin newer than $sinceMs, oldest first.
     *
     * @param string $coin
     * @param int $sinceMs
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    private function fetch(string $coin, int $sinceMs): array
    {
        $deposits = [];
        $olderThan = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = $this->client->getInternalDeposits($coin, $sinceMs, $olderThan, Client::MAX_LIMIT);
            foreach ($rows as $row) {
                $deposits[(string)($row['depId'] ?? '')] = $row;
            }
            if (count($rows) < Client::MAX_LIMIT) {
                break;
            }
            $olderThan = min(array_map(static fn (array $row): int => (int)($row['ts'] ?? 0), $rows));
        }
        unset($deposits['']);
        usort($deposits, static fn (array $a, array $b): int => (int)($a['ts'] ?? 0) <=> (int)($b['ts'] ?? 0));

        return $deposits;
    }

    /**
     * Earliest deposit time that can belong to the payment, in milliseconds.
     *
     * @param Payment $payment
     * @return int
     */
    private function startedAt(Payment $payment): int
    {
        return ((int)strtotime($payment->getCreatedAt() . ' UTC') - self::CLOCK_SKEW) * 1000;
    }

    /**
     * Amount with 4 decimals, or '' when it is not a plain number or has finer digits.
     *
     * @param string $amount
     * @return string
     */
    private function normalize(string $amount): string
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return '';
        }
        $normalized = bcadd($amount, '0', 4);

        return bccomp($amount, $normalized, 18) === 0 ? $normalized : '';
    }

    /**
     * Record a matched transfer; a failure is logged and the run goes on.
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
            $this->logger->critical('Could not record an OKX internal transfer', [
                'order_id' => $payment->getOrderId(),
                'reference' => $transfer->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
