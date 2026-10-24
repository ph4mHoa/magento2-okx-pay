<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Test\Unit\Block;

use JoePham\OkxPay\Block\Info;
use JoePham\OkxPay\Model\Config;
use JoePham\OkxPay\Model\PaymentInfo;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use PHPUnit\Framework\TestCase;

class InfoTest extends TestCase
{
    public function testReferenceAndPayerAreShownInTheAdmin(): void
    {
        $this->assertFalse($this->block(Area::AREA_ADMINHTML, Config::APP)->getIsSecureMode());
    }

    public function testReferenceAndPayerAreHiddenFromShoppers(): void
    {
        $this->assertTrue($this->block(Area::AREA_FRONTEND, Config::APP)->getIsSecureMode());
    }

    public function testEmailsAndPdfsPrintedFromTheAdminStayInSecureMode(): void
    {
        $block = $this->block(Area::AREA_ADMINHTML, Config::APP);
        $block->setIsSecureMode(true);

        $this->assertTrue($block->getIsSecureMode());
    }

    public function testLabelsNameTheKindOfReference(): void
    {
        $label = new \ReflectionMethod(Info::class, 'getLabel');

        $this->assertSame(
            'OKX Deposit ID',
            (string)$label->invoke($this->block(Area::AREA_ADMINHTML, Config::APP), PaymentInfo::REFERENCE)
        );
        $this->assertSame(
            'X Layer Transaction',
            (string)$label->invoke($this->block(Area::AREA_ADMINHTML, Config::WALLET), PaymentInfo::REFERENCE)
        );
        $this->assertSame(
            'Amount Received',
            (string)$label->invoke($this->block(Area::AREA_ADMINHTML, Config::WALLET), PaymentInfo::RECEIVED)
        );
    }

    /**
     * @param string $area
     * @param string $method
     * @return Info
     */
    private function block(string $area, string $method): Info
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn($area);
        $context = $this->createMock(Context::class);
        $context->method('getAppState')->willReturn($appState);
        $methodInstance = $this->createMock(MethodInterface::class);
        $methodInstance->method('getCode')->willReturn($method);
        $info = $this->createMock(InfoInterface::class);
        $info->method('getMethodInstance')->willReturn($methodInstance);

        $block = new Info($context, $this->createMock(ConfigInterface::class));
        $block->setData('info', $info);

        return $block;
    }
}
