<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Controller\Payment;

use JoePham\OkxPay\Model\PaymentRepository;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Payment page, reached through the unguessable link made when the payment started.
 */
class View implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param PaymentRepository $payments
     * @param PageFactory $pageFactory
     * @param ForwardFactory $forwardFactory
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PaymentRepository $payments,
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): ResultInterface
    {
        if ($this->payments->getByToken((string)$this->request->getParam('token')) === null) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->getConfig()->getTitle()->set(__('Complete your payment'));

        return $page;
    }
}
