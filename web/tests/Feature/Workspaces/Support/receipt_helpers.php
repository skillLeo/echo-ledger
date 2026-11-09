<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

// Shared by the payment receipt tests (v1.7): screenshots made with GD, JPEGs carrying EXIF, and small PDFs.

if (function_exists('receiptPicture')) {
    return;
}

/** A receipt screenshot: a light page with a coloured band, different for each seed. */
function receiptPicture(string $type = 'png', int $width = 600, int $height = 300, int $seed = 1): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 250, 250, 250));
    imagefilledrectangle($image, 0, 0, (int) ($width / 2), 40, (int) imagecolorallocate($image, ($seed * 53) % 256, ($seed * 29) % 256, 120));
    imagestring($image, 5, 20, 80, 'Transfer Rs 2,999 #'.$seed, (int) imagecolorallocate($image, 10, 10, 10));
    ob_start();
    match ($type) {
        'jpeg' => imagejpeg($image, null, 90),
        'webp' => imagewebp($image, null, 85),
        'gif' => imagegif($image),
        default => imagepng($image),
    };

    return (string) ob_get_clean();
}

/**
 * A JPEG with an EXIF block (orientation [$orientation], a description and a GPS-like text) and a comment, as a
 * phone camera writes them.
 */
function receiptJpegWithExif(int $width = 400, int $height = 200, int $orientation = 6, string $secret = 'SECRET-LOCATION-31.5204N'): string
{
    $jpeg = receiptPicture('jpeg', $width, $height);
    // TIFF, little-endian: IFD0 with Orientation (SHORT) and ImageDescription (ASCII, stored after the IFD).
    $text = $secret."\0";
    $ifdOffset = 8;
    $entries = 2;
    $dataOffset = $ifdOffset + 2 + $entries * 12 + 4;
    $tiff = 'II'.pack('v', 42).pack('V', $ifdOffset)
        .pack('v', $entries)
        .pack('vvV', 0x010E, 2, strlen($text)).pack('V', $dataOffset)
        .pack('vvV', 0x0112, 3, 1).pack('vv', $orientation, 0)
        .pack('V', 0)
        .$text;
    $app1 = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;
    $comment = 'SECRET-COMMENT camera';
    $com = "\xFF\xFE".pack('n', strlen($comment) + 2).$comment;

    return substr($jpeg, 0, 2).$segment.$com.substr($jpeg, 2);
}

/** A small, valid PDF. [$catalog] goes into the catalog's dictionary, [$trailer] into the trailer's. */
function receiptPdf(string $catalog = '', string $trailer = '', string $extraObjects = ''): string
{
    $content = 'BT /F1 12 Tf 72 720 Td (Transfer Rs 2,999 EL-TEST) Tj ET';

    return "%PDF-1.4\n"
        ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R {$catalog} >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>\nendobj\n"
        ."4 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj\n"
        .$extraObjects
        ."trailer\n<< /Root 1 0 R {$trailer} >>\n%%EOF\n";
}

/** An object stream (PDF 1.5), compressed, holding [$objects]. */
function receiptObjectStream(string $objects): string
{
    $data = (string) gzcompress("5 0 {$objects}");

    return "6 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length ".strlen($data)." >>\nstream\n{$data}\nendstream\nendobj\n";
}

function receiptUpload(string $bytes, string $name = 'receipt.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}
