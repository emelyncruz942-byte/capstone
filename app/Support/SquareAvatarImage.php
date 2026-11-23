<?php

namespace App\Support;

final class SquareAvatarImage
{
    public const MAX_EDGE = 512;
    public const MAX_COMPRESSED_BYTES = 131072;
    private const MAX_SOURCE_PIXELS = 16_000_000;

    /** Orient, centre-crop, and re-encode a new upload without altering its source. */
    public static function encode(string $path, string $mime, ?string $outputMime = null): ?string
    {
        $outputMime ??= $mime;
        if (!extension_loaded('gd')) return null;
        $dimensions = @getimagesize($path);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] * $dimensions[1] > self::MAX_SOURCE_PIXELS) return null;
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) return null;
        $source = @imagecreatefromstring($bytes);
        if ($source === false) return null;
        $square = null;
        try {
            $orientation = 1;
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($path, 'IFD0', true);
                $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
            }
            if (in_array($orientation, [2, 5, 7], true)) imageflip($source, IMG_FLIP_HORIZONTAL);
            if ($orientation === 4) imageflip($source, IMG_FLIP_VERTICAL);
            $degrees = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
            if ($degrees !== 0) {
                $rotated = imagerotate($source, $degrees, 0);
                if ($rotated === false) return null;
                imagedestroy($source); $source = $rotated;
            }
            $width = imagesx($source); $height = imagesy($source);
            $crop = min($width, $height); $edge = min(self::MAX_EDGE, $crop);
            $square = imagecreatetruecolor($edge, $edge);
            if ($square === false) return null;
            $jpeg = $outputMime === 'image/jpeg';
            imagealphablending($square, $jpeg); imagesavealpha($square, !$jpeg);
            imagefilledrectangle($square, 0, 0, $edge, $edge, imagecolorallocatealpha($square, 255, 255, 255, $jpeg ? 0 : 127));
            if (!imagecopyresampled($square, $source, 0, 0, intdiv($width - $crop, 2), intdiv($height - $crop, 2), $edge, $edge, $crop, $crop)) return null;
            ob_start();
            try {
                $encoded = match ($outputMime) {
                    'image/jpeg' => imagejpeg($square, null, 82),
                    'image/png' => imagepng($square, null, 7),
                    'image/webp' => function_exists('imagewebp') && imagewebp($square, null, 88),
                    default => false,
                };
                $result = ob_get_contents();
            } finally { ob_end_clean(); }
            if ($jpeg && $encoded && is_string($result)) {
                foreach ([72, 62, 52, 42] as $quality) {
                    if (strlen($result) <= self::MAX_COMPRESSED_BYTES) break;
                    ob_start();
                    try { $encoded = imagejpeg($square, null, $quality); $result = ob_get_contents(); }
                    finally { ob_end_clean(); }
                }
                if (strlen($result) > self::MAX_COMPRESSED_BYTES) return null;
            }
            return $encoded && is_string($result) && $result !== '' ? $result : null;
        } finally {
            imagedestroy($source);
            if ($square instanceof \GdImage) imagedestroy($square);
        }
    }
}
