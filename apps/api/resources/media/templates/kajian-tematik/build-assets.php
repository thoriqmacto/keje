<?php

/*
|--------------------------------------------------------------------------
| Kajian Tematik — static asset generator
|--------------------------------------------------------------------------
|
| Regenerates the committed template assets:
|
|   branding.png  #5 "KAJIAN / ● TEMATIK" wordmark, transparent, 2× the
|                 rendered size so FFmpeg downscales rather than upscales.
|   overlay.png   the readability gradient, built from the SAME stops the
|                 template config hands to the browser preview.
|
| Both files are version-controlled so a deploy never has to generate them.
| Re-run only when the branding or the overlay stops change:
|
|   php resources/media/templates/kajian-tematik/build-assets.php
|
*/

$dir = __DIR__;
$template = require $dir.'/template.php';

$bold = getenv('MEDIA_FONT_BOLD_FILE') ?: '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

if (! is_file($bold)) {
    fwrite(STDERR, "Bold font not found: {$bold}\n");
    exit(1);
}

// ── branding.png ────────────────────────────────────────────────────────────
// Drawn at 2× the 210×76 slot the template reserves.
$scale = 2;
$w = $template['elements']['branding']['width'] * $scale;
$h = $template['elements']['branding']['height'] * $scale;

$img = imagecreatetruecolor($w, $h);
imagesavealpha($img, true);
imagealphablending($img, false);
imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
imagealphablending($img, true);

$white = imagecolorallocate($img, 255, 255, 255);
$amber = imagecolorallocate($img, 232, 180, 74);

// GD sizes text in points at 96 DPI; the canvas is in pixels.
$pt = static fn (float $px): float => $px * 0.75;

// "KAJIAN" sits flush left; "● TEMATIK" is indented under it, matching the
// stacked wordmark in the design reference.
imagettftext($img, $pt(27 * $scale), 0, 6 * $scale, 31 * $scale, $white, $bold, 'KAJIAN');

$dotCx = 19 * $scale;
$dotCy = 56 * $scale;
imagefilledellipse($img, $dotCx, $dotCy, 9 * $scale, 9 * $scale, $amber);

imagettftext($img, $pt(27 * $scale), 0, 30 * $scale, 65 * $scale, $white, $bold, 'TEMATIK');

imagepng($img, $dir.'/branding.png');
imagedestroy($img);
echo "wrote branding.png ({$w}×{$h})\n";

// ── overlay.png ─────────────────────────────────────────────────────────────
// A 1-pixel-wide column is enough: FFmpeg stretches it across the canvas, and
// keeping it 1px wide keeps the committed asset tiny.
$oh = $template['canvas']['height'];
$stops = $template['background']['overlay']['stops'];

$overlay = imagecreatetruecolor(1, $oh);
imagesavealpha($overlay, true);
imagealphablending($overlay, false);

/** Linear interpolation between the surrounding stops. */
$alphaAt = static function (float $t) use ($stops): float {
    $prev = $stops[0];

    foreach ($stops as $stop) {
        if ($t <= $stop[0]) {
            $span = $stop[0] - $prev[0];
            $ratio = $span > 0 ? ($t - $prev[0]) / $span : 0.0;

            return $prev[1] + ($stop[1] - $prev[1]) * $ratio;
        }
        $prev = $stop;
    }

    return $prev[1];
};

for ($y = 0; $y < $oh; $y++) {
    $alpha = $alphaAt($oh > 1 ? $y / ($oh - 1) : 0.0);
    // GD alpha runs 0 (opaque) → 127 (transparent), the inverse of CSS.
    $gd = (int) round((1.0 - $alpha) * 127);
    imagesetpixel($overlay, 0, $y, imagecolorallocatealpha($overlay, 0, 0, 0, $gd));
}

imagepng($overlay, $dir.'/overlay.png');
imagedestroy($overlay);
echo "wrote overlay.png (1×{$oh})\n";
