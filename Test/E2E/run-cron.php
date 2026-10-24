<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 *
 * Runs the OKX payment cron job once, for the end-to-end tests:
 *   php run-cron.php <magento root> [<increment id whose payment window should end now>]
 */
declare(strict_types=1);

use JoePham\OkxPay\Model\PaymentProcessor;
use JoePham\OkxPay\Model\ResourceModel\Payment as PaymentResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Sales\Model\Order;

require rtrim($argv[1], '/') . '/app/bootstrap.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(Area::AREA_CRONTAB);

if (isset($argv[2])) {
    $order = $objectManager->create(Order::class)->loadByIncrementId($argv[2]);
    $resource = $objectManager->get(PaymentResource::class);
    $resource->getConnection()->update(
        $resource->getMainTable(),
        ['expires_at' => gmdate('Y-m-d H:i:s', time() - 600)],
        ['order_id = ?' => (int)$order->getId()]
    );
}

$objectManager->get(PaymentProcessor::class)->processAll();
echo "ok\n";
