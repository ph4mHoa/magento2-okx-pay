<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model\ResourceModel;

use JoePham\OkxPay\Model\Payment as PaymentModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * joepham_okxpay_payment table.
 */
class Payment extends AbstractDb
{
    public const TABLE = 'joepham_okxpay_payment';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'entity_id');
    }

    /**
     * Amounts that payments of this method still hold.
     *
     * Open ones, and recent ones, so that a late or repeated transfer is traced to the right order instead of
     * paying for someone else's.
     *
     * @param string $method
     * @param string $from lowest amount
     * @param string $to highest amount
     * @param string $reservedSince UTC date; payments whose window ended before it no longer hold their amount
     * @return string[]
     */
    public function getReservedAmounts(string $method, string $from, string $to, string $reservedSince): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['amount'])
            ->where('method = ?', $method)
            ->where('amount >= ?', $from)
            ->where('amount <= ?', $to)
            ->where(
                $connection->quoteInto('status = ?', PaymentModel::STATUS_PENDING)
                . ' OR ' . $connection->quoteInto('expires_at > ?', $reservedSince)
            );

        return $connection->fetchCol($select);
    }
}
