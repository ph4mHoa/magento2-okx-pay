<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\Okx;

use JoePham\OkxPay\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Read-only OKX API v5 client: the funding account's deposit history.
 */
class Client
{
    public const PATH_DEPOSIT_HISTORY = '/api/v5/asset/deposit-history';
    /** Deposit type of a transfer from another OKX account. */
    public const TYPE_INTERNAL = '3';
    public const MAX_LIMIT = 100;

    private const TIMEOUT = 20;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param Signer $signer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Signer $signer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Internal transfers received, newest first: depId, ccy, amt, from, fromWdId, state, ts...
     *
     * @param string $coin
     * @param int|null $newerThan Unix time in milliseconds
     * @param int|null $olderThan Unix time in milliseconds, to page back
     * @param int $limit at most 100
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function getInternalDeposits(string $coin, ?int $newerThan, ?int $olderThan, int $limit = 100): array
    {
        $query = ['ccy' => $coin, 'type' => self::TYPE_INTERNAL];
        if ($olderThan !== null) {
            $query['after'] = (string)$olderThan;
        }
        if ($newerThan !== null) {
            $query['before'] = (string)$newerThan;
        }
        $query['limit'] = (string)min(self::MAX_LIMIT, max(1, $limit));

        return array_values(array_filter($this->get(self::PATH_DEPOSIT_HISTORY, $query), 'is_array'));
    }

    /**
     * Send a signed GET request and return the "data" list of a successful response.
     *
     * @param string $path
     * @param array<string,string> $query
     * @return array<int|string, mixed>
     * @throws ApiException
     */
    private function get(string $path, array $query): array
    {
        $key = $this->config->getApiKey();
        $secret = $this->config->getApiSecret();
        $passphrase = $this->config->getApiPassphrase();
        if ($key === '' || $secret === '' || $passphrase === '') {
            throw new ApiException(__('The OKX API key, secret and passphrase are not configured.'));
        }

        // The signed path must be byte for byte the one requested.
        $requestPath = $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $timestamp = $this->signer->timestamp();

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setHeaders([
            'Content-Type' => 'application/json',
            'OK-ACCESS-KEY' => $key,
            'OK-ACCESS-SIGN' => $this->signer->sign($timestamp, 'GET', $requestPath, '', $secret),
            'OK-ACCESS-TIMESTAMP' => $timestamp,
            'OK-ACCESS-PASSPHRASE' => $passphrase,
        ]);

        try {
            $curl->get($this->config->getApiUrl() . $requestPath);
        } catch (\Exception $e) {
            $this->logger->error('OKX API request failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new ApiException(__('Could not connect to OKX: %1', $e->getMessage()), '', $e);
        }

        $status = (int)$curl->getStatus();
        $raw = (string)$curl->getBody();
        if ($this->config->isDebug(Config::APP)) {
            $this->logger->debug('OKX API call', ['request' => $requestPath, 'status' => $status, 'response' => $raw]);
        }

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new ApiException(__('Unexpected response from OKX (HTTP %1).', $status), (string)$status);
        }
        $code = (string)($response['code'] ?? $status);
        if ($code !== '0') {
            $message = (string)($response['msg'] ?? '');
            $this->logger->warning('OKX API error', ['path' => $path, 'code' => $code, 'message' => $message]);
            throw new ApiException(__('OKX error %1: %2', $code, $message), $code);
        }

        return (array)($response['data'] ?? []);
    }
}
