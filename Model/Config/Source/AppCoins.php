<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\Config\Source;

use JoePham\OkxPay\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Coins shoppers can send by OKX internal transfer.
 */
class AppCoins implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return array_map(
            static fn (string $coin): array => ['value' => $coin, 'label' => $coin],
            Config::APP_COINS
        );
    }
}
