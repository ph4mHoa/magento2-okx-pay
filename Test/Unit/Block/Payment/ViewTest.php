<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Block\Payment;

use JoePham\OkxPay\Block\Payment\View;
use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Payment;
use JoePham\OkxPay\Model\PaymentRepository;
use JoePham\OkxPay\Model\QrCode;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    private const ADDRESS = '0x9a3f0c2e5b7d41c8e6f2a1b0d4c7e9f3a5b8c1d2';

    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var QrCode|MockObject
     */
    private $qrCode;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getWalletAddress')->willReturn(self::ADDRESS);
        $this->config->method('getWalletTokens')->willReturn(['USDT0', 'USDG']);
        $this->config->method('getRpcUrl')->willReturn('https://xlayer.example/rpc?apikey=SECRET');
        $this->qrCode = $this->createMock(QrCode::class);
    }

    public function testWalletPageSendsTheExactAmountToTheMerchant(): void
    {
        $config = json_decode($this->view(Config::WALLET, '35.6001')->getScriptConfig(), true);

        $this->assertSame('0xc4', $config['wallet']['chainId']);
        $this->assertSame(
            '0xa9059cbb'
            . str_pad(substr(self::ADDRESS, 2), 64, '0', STR_PAD_LEFT)
            . str_pad('21f36e4', 64, '0', STR_PAD_LEFT), // 35.6001 USDT = 35600100 units
            $config['wallet']['data']
        );
        $this->assertSame(['USDT0', 'USDG'], array_column($config['wallet']['tokens'], 'code'));
    }

    public function testWalletPageNeverShowsTheConfiguredRpc(): void
    {
        $json = $this->view(Config::WALLET, '35.6001')->getScriptConfig();

        $this->assertSame(Config::PUBLIC_RPC_URL, json_decode($json, true)['wallet']['rpcUrl']);
        $this->assertStringNotContainsString('SECRET', $json);
    }

    public function testAppPageHasNoWalletSettings(): void
    {
        $config = json_decode($this->view(Config::APP, '12.5')->getScriptConfig(), true);

        $this->assertArrayNotHasKey('wallet', $config);
        $this->assertSame('pending', $config['status']);
        $this->assertSame('a1b2', $config['token']);
    }

    public function testAmountIsShownWithFourDecimals(): void
    {
        $this->assertSame('12.5000', $this->view(Config::APP, '12.5')->getAmount());
    }

    public function testWalletQrHoldsThePlainAddress(): void
    {
        $this->qrCode->expects($this->once())->method('svg')->with(self::ADDRESS)->willReturn('<svg/>');

        $this->view(Config::WALLET, '35.6001')->getQrSvg();
    }

    public function testAppQrOpensThisPageOnAPhone(): void
    {
        $this->qrCode->expects($this->once())
            ->method('svg')
            ->with('https://shop.test/joepham_okxpay/payment/view?token=a1b2')
            ->willReturn('<svg/>');

        $this->view(Config::APP, '12.5')->getQrSvg();
    }

    /**
     * @param string $method
     * @param string $amount
     * @return View
     */
    private function view(string $method, string $amount): View
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setData([
            'order_id' => 42,
            'method' => $method,
            'amount' => $amount,
            'access_token' => 'a1b2',
            'status' => Payment::STATUS_PENDING,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);
        $payments = $this->createMock(PaymentRepository::class);
        $payments->method('getByToken')->with('a1b2')->willReturn($payment);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('token')->willReturn('a1b2');
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            fn ($route, $params = []) => 'https://shop.test/' . $route
                . (isset($params['token']) ? '?token=' . $params['token'] : '')
        );
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk');

        return new View(
            $context,
            $payments,
            $this->createMock(OrderRepositoryInterface::class),
            $this->config,
            $this->qrCode,
            $formKey
        );
    }
}
