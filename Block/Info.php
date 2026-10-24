<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Block;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\PaymentInfo;
use Magento\Framework\App\Area;
use Magento\Payment\Block\ConfigurableInfo;

/**
 * OKX payment details on the order view (admin, customer account, emails).
 */
class Info extends ConfigurableInfo
{
    /**
     * Show the reference and the payer (privateInfoKeys) to the merchant in the admin, not to shoppers.
     *
     * Magento's default treats every order placed in a store view as secure, which hides them in the admin too.
     * Emails and PDFs set secure mode explicitly, so they still leave them out.
     *
     * @return bool
     */
    public function getIsSecureMode()
    {
        if ($this->hasIsSecureMode()) {
            return parent::getIsSecureMode();
        }

        return $this->_appState->getAreaCode() !== Area::AREA_ADMINHTML;
    }

    /**
     * @inheritdoc
     */
    protected function getLabel($field)
    {
        $wallet = $this->getMethod()->getCode() === Config::WALLET;
        $labels = [
            PaymentInfo::AMOUNT => __('Amount to Pay'),
            PaymentInfo::RECEIVED => __('Amount Received'),
            PaymentInfo::REFERENCE => $wallet ? __('X Layer Transaction') : __('OKX Deposit ID'),
            PaymentInfo::PAYER => $wallet ? __('Paid From Address') : __('Paid From OKX Account'),
        ];

        return $labels[$field] ?? $field;
    }
}
