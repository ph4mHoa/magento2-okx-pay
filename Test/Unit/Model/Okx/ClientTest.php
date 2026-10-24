<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Model\Okx;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\Okx\ApiException;
use JoePham\OkxPay\Model\Okx\Client;
use JoePham\OkxPay\Model\Okx\Signer;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClientTest extends TestCase
{
    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var Curl|MockObject
     */
    private $curl;

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @var string|null
     */
    private ?string $url = null;

    private Client $client;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getApiUrl')->willReturn('https://openapi.okx.com');
        $this->config->method('getApiKey')->willReturn('key-1');
        $this->config->method('getApiSecret')->willReturn('secret-1');
        $this->config->method('getApiPassphrase')->willReturn('pass-1');

        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('setHeaders')->willReturnCallback(function (array $headers): void {
            $this->headers = $headers;
        });
        $this->curl->method('get')->willReturnCallback(function (string $url): void {
            $this->url = $url;
        });
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->client = new Client(
            $this->config,
            $curlFactory,
            new Signer(),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testRequestIsSignedOverTheExactPathAndQuery(): void
    {
        $this->respond(200, ['code' => '0', 'msg' => '', 'data' => [['depId' => '1', 'amt' => '35.6001']]]);

        $rows = $this->client->getInternalDeposits('USDT', 1690000000000, 1700000000000);

        $path = '/api/v5/asset/deposit-history?ccy=USDT&type=3&after=1700000000000&before=1690000000000&limit=100';
        $this->assertSame('https://openapi.okx.com' . $path, $this->url);
        $this->assertSame('key-1', $this->headers['OK-ACCESS-KEY']);
        $this->assertSame('pass-1', $this->headers['OK-ACCESS-PASSPHRASE']);
        $this->assertSame(
            (new Signer())->sign($this->headers['OK-ACCESS-TIMESTAMP'], 'GET', $path, '', 'secret-1'),
            $this->headers['OK-ACCESS-SIGN']
        );
        $this->assertSame([['depId' => '1', 'amt' => '35.6001']], $rows);
    }

    public function testOptionalBoundsAreLeftOutAndTheLimitIsCapped(): void
    {
        $this->respond(200, ['code' => '0', 'data' => ['not a row', ['depId' => '2']]]);

        $rows = $this->client->getInternalDeposits('USDC', null, null, 500);

        $this->assertSame(
            'https://openapi.okx.com/api/v5/asset/deposit-history?ccy=USDC&type=3&limit=100',
            $this->url
        );
        $this->assertSame([['depId' => '2']], $rows);
    }

    public function testErrorCodeBecomesAnException(): void
    {
        $this->respond(401, ['code' => '50113', 'msg' => 'Invalid Sign.', 'data' => []]);

        try {
            $this->client->getInternalDeposits('USDT', null, null);
            $this->fail('An OKX error must throw.');
        } catch (ApiException $e) {
            $this->assertSame('50113', $e->getApiCode());
            $this->assertStringContainsString('Invalid Sign.', $e->getMessage());
        }
    }

    public function testNonJsonResponseBecomesAnException(): void
    {
        $this->curl->method('getStatus')->willReturn(502);
        $this->curl->method('getBody')->willReturn('<html>Bad gateway</html>');

        $this->expectException(ApiException::class);
        $this->client->getInternalDeposits('USDT', null, null);
    }

    public function testConnectionFailureBecomesAnException(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willThrowException(new \Exception('timeout'));
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);
        $client = new Client($this->config, $curlFactory, new Signer(), $this->createMock(LoggerInterface::class));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('timeout');
        $client->getInternalDeposits('USDT', null, null);
    }

    public function testMissingCredentialsStopBeforeAnyRequest(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('key');
        $config->method('getApiSecret')->willReturn('');
        $config->method('getApiPassphrase')->willReturn('pass');
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->expects($this->never())->method('create');
        $client = new Client($config, $curlFactory, new Signer(), $this->createMock(LoggerInterface::class));

        $this->expectException(ApiException::class);
        $client->getInternalDeposits('USDT', null, null);
    }

    /**
     * @param int $status
     * @param array $body
     * @return void
     */
    private function respond(int $status, array $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn((string)json_encode($body));
    }
}
