<?php

/**
 * Generates the social-preview image and favicons from the site's own brand mark.
 *
 * Run once after changing the mark or the palette; the output is committed so production
 * never generates anything at request time:
 *
 *     php tools/generate-og-image.php
 *
 * The mark mirrors the inline SVG in resources/views/docs/template.blade.php — a nucleus plus
 * three orbitals at 0/60/120 degrees — so the preview matches the header a visitor then sees.
 * Colours are the CSS design tokens: accent #2f6bdb on the dark #1b202a used for code blocks.
 *
 * Drawn at 3x and downsampled, because GD has no antialiasing for thick strokes; supersampling
 * is what keeps the orbitals from looking ragged.
 */

const W = 1200;
const H = 630;
const SS = 3;                 // supersample factor

$outDir = __DIR__ . '/../public/img';
@mkdir($outDir, 0777, true);

$font = null;
foreach ([
    'C:/Windows/Fonts/segoeuib.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
] as $candidate) {
    if (is_file($candidate)) { $font = $candidate; break; }
}
$fontRegular = null;
foreach ([
    'C:/Windows/Fonts/segoeui.ttf',
    'C:/Windows/Fonts/arial.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
] as $candidate) {
    if (is_file($candidate)) { $fontRegular = $candidate; break; }
}

if (!$font || !$fontRegular) {
    fwrite(STDERR, "No usable TTF found — install DejaVu or run on a machine with Segoe/Arial.\n");
    exit(1);
}

$im = imagecreatetruecolor(W * SS, H * SS);
imagealphablending($im, true);

$bg      = imagecolorallocate($im, 0x1b, 0x20, 0x2a);
$accent  = imagecolorallocate($im, 0x2f, 0x6b, 0xdb);
$white   = imagecolorallocate($im, 0xe6, 0xed, 0xf3);
$muted   = imagecolorallocate($im, 0x8b, 0x95, 0xa5);

imagefilledrectangle($im, 0, 0, W * SS, H * SS, $bg);

// Accent bar down the left edge — a cheap, recognisable brand cue at thumbnail size.
imagefilledrectangle($im, 0, 0, 14 * SS, H * SS, $accent);

/** Draw an ellipse outline rotated by $deg, as a thick polyline. */
function orbital($im, float $cx, float $cy, float $rx, float $ry, float $deg, int $color, float $thickness): void
{
    imagesetthickness($im, (int) round($thickness));
    $rad = deg2rad($deg);
    $cos = cos($rad);
    $sin = sin($rad);

    $prev = null;
    for ($t = 0; $t <= 360; $t += 2) {
        $a = deg2rad($t);
        $x = $rx * cos($a);
        $y = $ry * sin($a);
        $px = $cx + ($x * $cos - $y * $sin);
        $py = $cy + ($x * $sin + $y * $cos);

        if ($prev !== null) {
            imageline($im, (int) $prev[0], (int) $prev[1], (int) $px, (int) $py, $color);
        }
        $prev = [$px, $py];
    }
    imagesetthickness($im, 1);
}

// --- brand mark ------------------------------------------------------------------------
$markCx = 250 * SS;
$markCy = (H / 2) * SS;
$r      = 150 * SS;           // orbital major radius

foreach ([0, 60, 120] as $deg) {
    orbital($im, $markCx, $markCy, $r, $r * 0.44, (float) $deg, $accent, 5 * SS);
}
imagefilledellipse($im, (int) $markCx, (int) $markCy, (int) (66 * SS), (int) (66 * SS), $white);

// --- wordmark --------------------------------------------------------------------------
$textX = 470 * SS;

imagettftext($im, 104 * SS, 0, $textX, (int) ((H / 2 - 34) * SS), $white, $font, 'Atom');
imagettftext($im, 34 * SS, 0, $textX + 4 * SS, (int) ((H / 2 + 34) * SS), $accent, $font, 'PHP FRAMEWORK');
imagettftext($im, 27 * SS, 0, $textX + 4 * SS, (int) ((H / 2 + 96) * SS), $muted, $fontRegular, 'Documentation · basttyydev.serv00.net');

// --- downsample ------------------------------------------------------------------------
$out = imagecreatetruecolor(W, H);
imagecopyresampled($out, $im, 0, 0, 0, 0, W, H, W * SS, H * SS);
imagepng($out, $outDir . '/og-default.png', 9);
printf("wrote %s (%d bytes)\n", 'public/img/og-default.png', filesize($outDir . '/og-default.png'));

// --- favicons --------------------------------------------------------------------------
foreach ([32, 180] as $size) {
    $ic = imagecreatetruecolor($size * SS, $size * SS);
    imagefilledrectangle($ic, 0, 0, $size * SS, $size * SS, $bg);
    $c = ($size / 2) * SS;
    foreach ([0, 60, 120] as $deg) {
        orbital($ic, $c, $c, $size * 0.40 * SS, $size * 0.40 * 0.44 * SS, (float) $deg, $accent, max(1, $size / 14) * SS);
    }
    imagefilledellipse($ic, (int) $c, (int) $c, (int) ($size * 0.20 * SS), (int) ($size * 0.20 * SS), $white);

    $small = imagecreatetruecolor($size, $size);
    imagecopyresampled($small, $ic, 0, 0, 0, 0, $size, $size, $size * SS, $size * SS);
    $name = $size === 32 ? 'favicon-32.png' : 'apple-touch-icon.png';
    imagepng($small, $outDir . '/' . $name, 9);
    printf("wrote public/img/%s\n", $name);
}
