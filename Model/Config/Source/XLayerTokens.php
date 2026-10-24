<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\Config\Source;

use JoePham\OkxPay\Model\XLayer\Token;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Stablecoins accepted on X Layer, labelled with their contract address.
 */
class XLayerTokens implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (Token::TOKENS as $code => $token) {
            $options[] = ['value' => $code, 'label' => $token['symbol'] . ' (' . $token['address'] . ')'];
        }

        return $options;
    }
}
