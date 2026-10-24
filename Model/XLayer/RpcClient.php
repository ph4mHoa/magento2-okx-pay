<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\XLayer;

use JoePham\OkxPay\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * The few X Layer JSON-RPC calls needed to see token transfers. Read only: no key, no signing.
 */
class RpcClient
{
    private const TIMEOUT = 15;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Number of the latest block.
     *
     * @return int
     * @throws RpcException
     */
    public function getBlockNumber(): int
    {
        return (int)Token::hexToDecimal((string)$this->call('eth_blockNumber', []));
    }

    /**
     * Transfer events of the given tokens to $recipient in a block range.
     *
     * @param int $fromBlock
     * @param int $toBlock
     * @param string[] $tokenAddresses
     * @param string $recipient
     * @return array<int, array<string, mixed>>
     * @throws RpcException
     */
    public function getTransferLogs(int $fromBlock, int $toBlock, array $tokenAddresses, string $recipient): array
    {
        $logs = $this->call('eth_getLogs', [[
            'fromBlock' => '0x' . dechex($fromBlock),
            'toBlock' => '0x' . dechex($toBlock),
            'address' => array_values($tokenAddresses),
            'topics' => [Token::TRANSFER_TOPIC, null, Token::addressToTopic($recipient)],
        ]]);

        return is_array($logs) ? array_values(array_filter($logs, 'is_array')) : [];
    }

    /**
     * Receipt of a mined transaction, or null while it is not mined.
     *
     * @param string $txHash
     * @return array<string, mixed>|null
     * @throws RpcException
     */
    public function getTransactionReceipt(string $txHash): ?array
    {
        $receipt = $this->call('eth_getTransactionReceipt', [$txHash]);

        return is_array($receipt) ? $receipt : null;
    }

    /**
     * Send one JSON-RPC request and return its result.
     *
     * @param string $method
     * @param mixed[] $params
     * @return mixed
     * @throws RpcException
     */
    private function call(string $method, array $params): mixed
    {
        $body = (string)json_encode(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            JSON_UNESCAPED_SLASHES
        );
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setHeaders(['Content-Type' => 'application/json']);

        try {
            $curl->post($this->config->getRpcUrl(), $body);
        } catch (\Exception $e) {
            $this->logger->error('X Layer RPC request failed', ['method' => $method, 'error' => $e->getMessage()]);
            throw new RpcException(__('Could not connect to the X Layer RPC: %1', $e->getMessage()), $e);
        }

        $status = (int)$curl->getStatus();
        $raw = (string)$curl->getBody();
        if ($this->config->isDebug(Config::WALLET)) {
            $this->logger->debug('X Layer RPC call', ['request' => $body, 'status' => $status, 'response' => $raw]);
        }

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new RpcException(__('Unexpected response from the X Layer RPC (HTTP %1).', $status));
        }
        if (isset($response['error'])) {
            $error = (array)$response['error'];
            $this->logger->warning('X Layer RPC error', ['method' => $method, 'error' => $error]);
            throw new RpcException(__(
                'X Layer RPC error %1: %2',
                (string)($error['code'] ?? ''),
                (string)($error['message'] ?? '')
            ));
        }

        return $response['result'] ?? null;
    }
}
