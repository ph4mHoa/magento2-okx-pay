<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\Okx;

/**
 * OKX API v5 request signature.
 *
 * @see https://www.okx.com/docs-v5/en/#overview-rest-authentication-making-requests
 */
class Signer
{
    /**
     * Base64 HMAC-SHA256 of timestamp + METHOD + request path with query + body.
     *
     * @param string $timestamp ISO 8601 with milliseconds, as sent in OK-ACCESS-TIMESTAMP
     * @param string $method
     * @param string $requestPath e.g. /api/v5/asset/deposit-history?ccy=USDT
     * @param string $body empty for GET
     * @param string $secret
     * @return string
     */
    public function sign(string $timestamp, string $method, string $requestPath, string $body, string $secret): string
    {
        return base64_encode(
            hash_hmac('sha256', $timestamp . strtoupper($method) . $requestPath . $body, $secret, true)
        );
    }

    /**
     * Current UTC time as OKX expects it, e.g. 2026-10-25T09:08:57.715Z.
     *
     * @param float|null $time Unix time with fractions, now by default
     * @return string
     */
    public function timestamp(?float $time = null): string
    {
        $time ??= microtime(true);
        $seconds = (int)floor($time);

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', (int)(($time - $seconds) * 1000));
    }
}
