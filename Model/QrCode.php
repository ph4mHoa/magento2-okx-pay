<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\OkxPay\Model;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes drawn on the server as inline SVG, so the payment page loads no third-party script.
 */
class QrCode
{
    /**
     * SVG markup of a QR code for $text, without the XML declaration.
     *
     * @param string $text
     * @param int $size pixels
     * @return string
     */
    public function svg(string $text, int $size = 200): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));

        return trim((string)preg_replace('/^<\?xml[^>]*\?>/', '', $writer->writeString($text)));
    }
}
