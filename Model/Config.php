<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use JoePham\OkxPay\Model\XLayer\Token;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed access to the payment/joepham_okx_app and payment/joepham_okx_wallet settings.
 *
 * The OKX account and the wallet address are global: one account receives the payments of every website.
 */
class Config
{
    public const APP = 'joepham_okx_app';
    public const WALLET = 'joepham_okx_wallet';
    public const METHODS = [self::APP, self::WALLET];

    public const APP_COINS = ['USDT', 'USDC'];

    private const DEFAULT_API_URL = 'https://openapi.okx.com';
    /** Public X Layer RPC, also given to wallets that do not know the chain yet. */
    public const PUBLIC_RPC_URL = 'https://rpc.xlayer.tech';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * OKX REST API host, without a trailing slash.
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        return rtrim($this->value(self::APP, 'api_url') ?: self::DEFAULT_API_URL, '/');
    }

    /**
     * OKX API key (read permission).
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->value(self::APP, 'api_key');
    }

    /**
     * OKX API secret.
     *
     * @return string
     */
    public function getApiSecret(): string
    {
        return $this->value(self::APP, 'api_secret');
    }

    /**
     * OKX API passphrase.
     *
     * @return string
     */
    public function getApiPassphrase(): string
    {
        return $this->value(self::APP, 'api_passphrase');
    }

    /**
     * The OKX account (email, phone or UID) shoppers send the transfer to.
     *
     * @return string
     */
    public function getRecipient(): string
    {
        return $this->value(self::APP, 'recipient');
    }

    /**
     * Coins accepted by internal transfer, e.g. ['USDT'].
     *
     * @return string[]
     */
    public function getAppCoins(): array
    {
        $coins = array_values(array_intersect(self::APP_COINS, $this->list(self::APP, 'coins')));

        return $coins ?: ['USDT'];
    }

    /**
     * Names of the coins a method accepts, e.g. ['USDT'] or ['USD₮0', 'USDG'].
     *
     * @param string $method
     * @return string[]
     */
    public function getCoinNames(string $method): array
    {
        if ($method === self::APP) {
            return $this->getAppCoins();
        }

        return array_map(
            static fn (string $code): string => Token::TOKENS[$code]['symbol'],
            $this->getWalletTokens()
        );
    }

    /**
     * Receiving X Layer address, lower case.
     *
     * @return string
     */
    public function getWalletAddress(): string
    {
        return strtolower($this->value(self::WALLET, 'address'));
    }

    /**
     * Codes of the accepted X Layer tokens, e.g. ['USDT0', 'USDG'].
     *
     * @return string[]
     */
    public function getWalletTokens(): array
    {
        $tokens = array_values(array_intersect(array_keys(Token::TOKENS), $this->list(self::WALLET, 'tokens')));

        return $tokens ?: ['USDT0'];
    }

    /**
     * X Layer JSON-RPC endpoint.
     *
     * @return string
     */
    public function getRpcUrl(): string
    {
        return $this->value(self::WALLET, 'rpc_url') ?: self::PUBLIC_RPC_URL;
    }

    /**
     * Blocks a transfer must be buried under before it counts (1 = included in the latest block).
     *
     * @return int
     */
    public function getConfirmations(): int
    {
        return max(1, (int)$this->value(self::WALLET, 'confirmations'));
    }

    /**
     * Largest block range per eth_getLogs call.
     *
     * @return int
     */
    public function getLogBlockRange(): int
    {
        return min(10000, max(1, (int)($this->value(self::WALLET, 'log_block_range') ?: 100)));
    }

    /**
     * Minutes a shopper has to pay.
     *
     * @param string $method
     * @return int
     */
    public function getPaymentWindow(string $method): int
    {
        return min(1440, max(5, (int)($this->value($method, 'payment_window') ?: 30)));
    }

    /**
     * Whether everything the app transfer needs is filled in.
     *
     * @return bool
     */
    public function isAppConfigured(): bool
    {
        return $this->getRecipient() !== '' && $this->getApiKey() !== '' && $this->getApiSecret() !== ''
            && $this->getApiPassphrase() !== '';
    }

    /**
     * Whether a valid receiving address is filled in.
     *
     * @return bool
     */
    public function isWalletConfigured(): bool
    {
        return (bool)preg_match('/^0x[0-9a-f]{40}$/', $this->getWalletAddress());
    }

    /**
     * Whether API and RPC traffic is logged.
     *
     * @param string $method
     * @return bool
     */
    public function isDebug(string $method): bool
    {
        return (bool)$this->value($method, 'debug');
    }

    /**
     * Global setting of a method.
     *
     * @param string $method
     * @param string $field
     * @return string
     */
    private function value(string $method, string $field): string
    {
        return trim((string)$this->scopeConfig->getValue('payment/' . $method . '/' . $field));
    }

    /**
     * Comma-separated setting as a list.
     *
     * @param string $method
     * @param string $field
     * @return string[]
     */
    private function list(string $method, string $field): array
    {
        return array_filter(array_map('trim', explode(',', $this->value($method, $field))));
    }
}
