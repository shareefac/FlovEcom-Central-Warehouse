<?php

declare(strict_types=1);

namespace CW\Output;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * A QR code as a grid of dark and light modules (bacon/bacon-qr-code's encoder, BSD-2-Clause; docs/decisions.md Y21). The staff
 * screens draw it as HTML (one element per module, two CSS classes): no image, no SVG, no data: URI, nothing stored or fetched
 * again, so the sign-in secret it carries leaves the server once, in the answer of the button that made it (Kernel: no-store).
 */
final class QrCode
{
    /** Light modules around the code: the 4 the QR standard asks for. */
    public const QUIET = 4;
    /** The longest text drawn (an otpauth:// address is about 150 characters). */
    public const MAX_TEXT = 600;

    /**
     * The rows of the code, quiet zone included: true = dark. Error correction M (15 %), byte mode, ISO-8859-1 (an otpauth:// URI is
     * ASCII). 400-free: a text too long or not ASCII is a programming error (\InvalidArgumentException).
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $text): array
    {
        if ($text === '' || strlen($text) > self::MAX_TEXT || preg_match('/^[\x20-\x7e]+$/D', $text) !== 1) {
            throw new \InvalidArgumentException('a QR code here carries 1 to ' . self::MAX_TEXT . ' printable ASCII characters');
        }
        $m = Encoder::encode($text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING, null, false)->getMatrix();
        $w = $m->getWidth();
        $h = $m->getHeight();
        $size = $w + 2 * self::QUIET;
        $light = array_fill(0, $size, false);
        $rows = array_fill(0, self::QUIET, $light);
        for ($y = 0; $y < $h; $y++) {
            $row = $light;
            for ($x = 0; $x < $w; $x++) {
                $row[$x + self::QUIET] = $m->get($x, $y) === 1;
            }
            $rows[] = $row;
        }
        for ($i = 0; $i < self::QUIET; $i++) {
            $rows[] = $light;
        }
        return $rows;
    }
}
