<?php

declare(strict_types=1);

namespace MyInvoice\Service\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR kód s obyčejnou URL (např. „Zaplatit online") jako PNG data URI pro mPDF. */
final class UrlQrCode
{
    public static function pngDataUri(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel'        => EccLevel::M,
            'scale'           => 6,
            'imageBase64'     => true,
            'quietzoneSize'   => 2,
        ]);
        return (new QRCode($options))->render($url);
    }
}
