<?php

// Regenerates the Pairly icon and favicon from the logo's geometry: php docs/app-store/assets/make-icon.php
// Writes docs/app-store/assets/icon-1200.png (1200x1200, square, no text) and web/public/favicon.ico.
// Needs PHP's GD extension. Same grid as web/public/brand/logo.svg: a solid disc and a ring that touch.

function drawMark(int $size): GdImage
{
    $scale = 4; // Drawn large and resampled, because GD's ellipses have no anti-aliasing.
    $big = imagecreatetruecolor($size * $scale, $size * $scale);
    $unit = $size * $scale / 1200;
    $cobalt = imagecolorallocate($big, 0x1A, 0x2A, 0xE6);
    $white = imagecolorallocate($big, 0xFF, 0xFF, 0xFF);
    $sun = imagecolorallocate($big, 0xFF, 0xE1, 0x00);

    imagefilledrectangle($big, 0, 0, $size * $scale, $size * $scale, $cobalt);
    $disc = fn (int $x, int $y, int $r, int $color) => imagefilledellipse($big, (int) ($x * $unit), (int) ($y * $unit), (int) (2 * $r * $unit), (int) (2 * $r * $unit), $color);
    $disc(430, 430, 240, $white);
    $disc(770, 770, 240, $sun);
    $disc(770, 770, 118, $cobalt);

    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, $size * $scale, $size * $scale);
    imagedestroy($big);

    return $out;
}

function png(GdImage $image): string
{
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

file_put_contents(__DIR__.'/icon-1200.png', png(drawMark(1200)));

// An .ico file holding one PNG per size.
$sizes = [16, 32, 48];
$images = array_map(fn (int $s) => png(drawMark($s)), $sizes);
$ico = pack('vvv', 0, 1, count($sizes));
$offset = 6 + 16 * count($sizes);
foreach ($sizes as $i => $s) {
    $ico .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, strlen($images[$i]), $offset);
    $offset += strlen($images[$i]);
}
file_put_contents(__DIR__.'/../../../web/public/favicon.ico', $ico.implode('', $images));
