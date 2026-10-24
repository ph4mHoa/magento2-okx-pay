<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\Okx;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * The OKX API could not be reached or returned an error code.
 */
class ApiException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param string $apiCode OKX error code, e.g. "50113"
     * @param \Exception|null $cause
     */
    public function __construct(Phrase $phrase, private readonly string $apiCode = '', ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }

    /**
     * OKX error code.
     *
     * @return string
     */
    public function getApiCode(): string
    {
        return $this->apiCode;
    }
}
