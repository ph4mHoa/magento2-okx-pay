<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Block\Payment;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\QrCode;
use JoePham\OkxPay\Model\XLayer\Token;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * The page where the shopper sees what to send, and where to.
 */
class View extends Template
{
    private const POLL_INTERVAL = 4000;

    /**
     * @var Payment|null|false false until loaded
     */
    private $payment = false;

    /**
     * @param Context $context
     * @param PaymentRepository $payments
     * @param OrderRepositoryInterface $orderRepository
     * @param Config $config
     * @param QrCode $qrCode
     * @param FormKey $formKey
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly PaymentRepository $payments,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Config $config,
        private readonly QrCode $qrCode,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Payment named by the token in the URL.
     *
     * @return Payment|null
     */
    public function getPayment(): ?Payment
    {
        if ($this->payment === false) {
            $this->payment = $this->payments->getByToken((string)$this->getRequest()->getParam('token'));
        }

        return $this->payment;
    }

    /**
     * Order being paid.
     *
     * @return OrderInterface
     */
    public function getOrder(): OrderInterface
    {
        return $this->orderRepository->get((int)$this->getPayment()->getOrderId());
    }

    /**
     * Whether this is an OKX Wallet (X Layer) payment rather than an app transfer.
     *
     * @return bool
     */
    public function isWallet(): bool
    {
        return $this->getPayment()->getMethod() === Config::WALLET;
    }

    /**
     * Amount to send, 4 decimals.
     *
     * @return string
     */
    public function getAmount(): string
    {
        return bcadd((string)$this->getPayment()->getAmount(), '0', 4);
    }

    /**
     * Coins accepted for this payment.
     *
     * @return string[]
     */
    public function getCoinNames(): array
    {
        return $this->config->getCoinNames((string)$this->getPayment()->getMethod());
    }

    /**
     * OKX account that receives app transfers.
     *
     * @return string
     */
    public function getRecipient(): string
    {
        return $this->config->getRecipient();
    }

    /**
     * Address that receives wallet payments.
     *
     * @return string
     */
    public function getWalletAddress(): string
    {
        return $this->config->getWalletAddress();
    }

    /**
     * Accepted X Layer tokens.
     *
     * @return array<int, array{code: string, symbol: string, address: string}>
     */
    public function getTokens(): array
    {
        $tokens = [];
        foreach ($this->config->getWalletTokens() as $code) {
            $tokens[] = ['code' => $code] + Token::TOKENS[$code];
        }

        return $tokens;
    }

    /**
     * QR code: the receiving address for a wallet payment, this page for an app transfer (to continue on a phone).
     *
     * @return string SVG markup
     */
    public function getQrSvg(): string
    {
        return $this->qrCode->svg($this->isWallet() ? $this->getWalletAddress() : $this->getPageUrl());
    }

    /**
     * Link to this page.
     *
     * @return string
     */
    public function getPageUrl(): string
    {
        return $this->getUrl('joepham_okxpay/payment/view', [
            'token' => $this->getPayment()->getAccessToken(),
            '_nosid' => true,
        ]);
    }

    /**
     * Form target of the cancel button.
     *
     * @return string
     */
    public function getCancelUrl(): string
    {
        return $this->getUrl('joepham_okxpay/payment/cancel', ['_nosid' => true]);
    }

    /**
     * Form key for the POST requests of this page.
     *
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Settings for the page script, as JSON.
     *
     * @return string
     */
    public function getScriptConfig(): string
    {
        $payment = $this->getPayment();
        $config = [
            'token' => $payment->getAccessToken(),
            'status' => $payment->getDisplayStatus(),
            'secondsLeft' => $payment->getSecondsLeft(),
            'statusUrl' => $this->getUrl('joepham_okxpay/payment/status', ['_nosid' => true]),
            'submitUrl' => $this->getUrl('joepham_okxpay/payment/submit', ['_nosid' => true]),
            'formKey' => $this->getFormKey(),
            'pollInterval' => self::POLL_INTERVAL,
        ];
        if ($this->isWallet()) {
            $config['wallet'] = [
                'chainId' => '0x' . dechex(Token::CHAIN_ID),
                'chainName' => 'X Layer Mainnet',
                // Never the configured RPC: it may carry the merchant's provider key.
                'rpcUrl' => Config::PUBLIC_RPC_URL,
                'explorerUrl' => 'https://web3.okx.com/explorer/x-layer',
                'data' => Token::encodeTransfer($this->getWalletAddress(), Token::toUnits($this->getAmount())),
                'tokens' => $this->getTokens(),
            ];
        }

        return (string)json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
