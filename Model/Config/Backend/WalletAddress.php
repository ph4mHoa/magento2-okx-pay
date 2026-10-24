<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\Config\Backend;

use JoePham\OkxPay\Model\XLayer\Token;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Receiving address: an EVM address, stored in lower case.
 */
class WalletAddress extends Value
{
    /**
     * Reject anything that is not a plain address, including a token contract pasted by mistake.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $address = strtolower(trim((string)$this->getValue()));
        if ($address !== '' && !preg_match('/^0x[0-9a-f]{40}$/', $address)) {
            throw new LocalizedException(
                __('The receiving wallet address must be 0x followed by 40 hexadecimal characters.')
            );
        }
        if ($address !== '' && Token::codeOf($address) !== null) {
            throw new LocalizedException(
                __('%1 is a token contract, not a wallet address. Enter the address of your own wallet.', $address)
            );
        }
        $this->setValue($address);

        return parent::beforeSave();
    }
}
