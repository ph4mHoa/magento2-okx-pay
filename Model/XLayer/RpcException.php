<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\XLayer;

use Magento\Framework\Exception\LocalizedException;

/**
 * The X Layer RPC could not be reached or answered with an error.
 */
class RpcException extends LocalizedException
{
}
