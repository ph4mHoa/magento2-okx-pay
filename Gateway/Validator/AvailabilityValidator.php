<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Gateway\Validator;

use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\UsdConverter;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Quote\Model\Quote\Payment as QuotePayment;

/**
 * Offers the method only when it is set up and the cart total can be priced in US dollars.
 */
class AvailabilityValidator extends AbstractValidator
{
    /**
     * @param ResultInterfaceFactory $resultFactory
     * @param Config $config
     * @param UsdConverter $usdConverter
     * @param string $method Config::APP or Config::WALLET
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        private readonly Config $config,
        private readonly UsdConverter $usdConverter,
        private readonly string $method
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * @inheritdoc
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $configured = $this->method === Config::APP
            ? $this->config->isAppConfigured()
            : $this->config->isWalletConfigured();
        if (!$configured) {
            return $this->createResult(false);
        }

        $payment = SubjectReader::readPayment($validationSubject)->getPayment();
        if ($payment instanceof QuotePayment && $payment->getQuote() !== null) {
            $quote = $payment->getQuote();

            return $this->createResult($this->usdConverter->canConvert(
                (string)$quote->getBaseCurrencyCode(),
                (string)$quote->getQuoteCurrencyCode()
            ));
        }

        return $this->createResult(true);
    }
}
