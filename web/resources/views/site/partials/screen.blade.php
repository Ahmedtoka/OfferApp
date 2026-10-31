@php
    // One product screen as inline SVG: the static fallback, and the source of the 3D scene's textures.
    // Coordinates are written left to right and mirrored for Arabic, so both languages share one drawing.
    $rtl = $dir === 'rtl';
    $s = "site.screens.$name.";
    $ink = '#070C3A'; $muted = '#5A6088'; $line = '#DDE1F5'; $cobalt = '#1A2AE6'; $sun = '#FFE100'; $wash = '#F3F5FF';

    $text = function (int $x, int $y, string $value, int $size = 15, int $weight = 400, string $fill = '#070C3A', string $align = 'start') use ($rtl) {
        $px = $rtl ? 390 - $x : $x;
        $anchor = ['start' => 'start', 'end' => 'end', 'middle' => 'middle'][$align];

        return '<text x="'.$px.'" y="'.$y.'" font-size="'.$size.'" font-weight="'.$weight.'" fill="'.$fill.'" text-anchor="'.$anchor.'">'.e($value).'</text>';
    };
    $box = function (int $x, int $y, int $w, int $h, int $r, string $fill, ?string $stroke = null) use ($rtl) {
        $px = $rtl ? 390 - $x - $w : $x;

        return '<rect x="'.$px.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="'.$r.'" fill="'.$fill.'"'.($stroke ? ' stroke="'.$stroke.'" stroke-width="1.5"' : '').'/>';
    };
    $dot = function (int $x, int $y, int $r, string $fill, ?string $stroke = null, int $width = 0) use ($rtl) {
        $px = $rtl ? 390 - $x : $x;

        return '<circle cx="'.$px.'" cy="'.$y.'" r="'.$r.'" fill="'.$fill.'"'.($stroke ? ' stroke="'.$stroke.'" stroke-width="'.$width.'"' : '').'/>';
    };
    $t = fn (string $key) => __($s.$key);
    $o = [$box(0, 0, 390, 800, 0, '#FFFFFF')];
    $o[] = $text(24, 74, $t('title'), 30, 800);

    if ($name === 'settings') {
        $o[] = $box(24, 92, 342, 34, 17, $wash);
        $o[] = $dot(42, 109, 6, $cobalt);
        $o[] = $text(58, 114, $t('running'), 14, 600, $cobalt);
        $o[] = $text(24, 162, $t('percent'), 14, 500, $muted);
        $o[] = $box(24, 174, 342, 84, 14, $sun);
        $o[] = $text(42, 234, '30%', 50, 800);
        $o[] = $text(24, 288, $t('applies'), 14, 500, $muted);
        $o[] = $box(24, 300, 342, 44, 12, $wash);
        $o[] = $box(28, 304, 166, 36, 9, $cobalt);
        $o[] = $text(111, 328, $t('collection'), 14, 700, '#FFFFFF', 'middle');
        $o[] = $text(278, 328, $t('tag'), 14, 500, $muted, 'middle');
        $o[] = $box(24, 354, 342, 44, 12, '#FFFFFF', $line);
        $o[] = $text(40, 382, $t('collection_value'), 15, 600);
        $o[] = $text(24, 428, $t('method'), 14, 500, $muted);
        $o[] = $dot(36, 454, 8, $cobalt);
        $o[] = $text(56, 459, $t('method_customer'), 15, 600);
        $o[] = $dot(36, 486, 7, '#FFFFFF', $muted, 2);
        $o[] = $text(56, 491, $t('method_store'), 15, 400, $muted);
        $o[] = $text(24, 532, $t('dates'), 14, 500, $muted);
        $o[] = $box(24, 544, 342, 44, 12, '#FFFFFF', $line);
        $o[] = $text(40, 572, $t('dates_value'), 15, 600);
        $o[] = $text(24, 630, $t('coupons'), 15, 600);
        $o[] = $box(318, 612, 48, 26, 13, $line);
        $o[] = $dot(332, 625, 10, '#FFFFFF');
        $o[] = $box(24, 668, 342, 56, 14, $cobalt);
        $o[] = $text(195, 703, $t('save'), 17, 700, '#FFFFFF', 'middle');
    } elseif ($name === 'test') {
        $o[] = $text(24, 112, $t('sample'), 15, 500, $muted);
        foreach ([['piece_a', '$12'], ['piece_b', '$11'], ['piece_c', '$9']] as $i => [$label, $price]) {
            $y = 128 + $i * 50;
            $o[] = $box(24, $y, 342, 42, 10, $wash);
            $o[] = $text(40, $y + 27, $t($label), 15, 600);
            $o[] = $text(350, $y + 27, $price, 15, 700, $ink, 'end');
        }
        $cards = [
            [290, 'customer', '$18.30', [['piece_a', '$12', false], ['piece_b', $t('free'), true], ['piece_c', '$6.30', false]], true],
            [520, 'store', '$19.70', [['piece_a', '$12', false], ['piece_b', '$7.70', false], ['piece_c', $t('free'), true]], false],
        ];
        foreach ($cards as [$y, $key, $total, $rows, $chosen]) {
            $o[] = $box(24, $y, 342, 214, 16, '#FFFFFF', $chosen ? $cobalt : $line);
            $o[] = $dot(44, $y + 28, 7, $chosen ? $cobalt : '#FFFFFF', $chosen ? null : $muted, 2);
            $o[] = $text(60, $y + 33, $t($key), 15, 700);
            foreach ($rows as $i => [$label, $value, $free]) {
                $ry = $y + 64 + $i * 34;
                if ($free) {
                    $o[] = $box(36, $ry - 20, 318, 30, 8, $sun);
                }
                $o[] = $text(44, $ry, $t($label), 14, 500);
                $o[] = $text(346, $ry, $value, 14, 700, $ink, 'end');
            }
            $o[] = $text(44, $y + 192, $t('pays'), 15, 500, $muted);
            $o[] = $text(346, $y + 192, $total, 20, 800, $ink, 'end');
        }
    } elseif ($name === 'cart') {
        foreach ([[110, 'dress', '$40', false], [236, 'scarf', '$30', true]] as [$y, $key, $price, $free]) {
            $o[] = $box(24, $y, 92, 108, 14, $free ? $sun : $cobalt);
            $o[] = $text(132, $y + 36, $t($key), 17, 700);
            $o[] = $text(132, $y + 64, $price, 15, $free ? 400 : 600, $free ? $muted : $ink);
            if ($free) {
                $o[] = $box(132, $y + 76, 72, 26, 13, $sun);
                $o[] = $text(168, $y + 94, $t('free'), 13, 800, $ink, 'middle');
            }
        }
        $o[] = $box(24, 372, 342, 68, 14, $cobalt);
        $o[] = $text(195, 412, $t('message'), 15, 700, '#FFFFFF', 'middle');
        $o[] = $text(24, 490, $t('discount'), 14, 500, $muted);
        $o[] = $text(366, 490, '-$30', 14, 700, $ink, 'end');
        $o[] = $box(24, 510, 342, 1, 0, $line);
        $o[] = $text(24, 560, $t('total'), 18, 700);
        $o[] = $text(366, 560, '$40', 30, 800, $ink, 'end');
        $o[] = $box(24, 668, 342, 56, 14, $ink);
        $o[] = $text(195, 703, $t('checkout'), 17, 700, '#FFFFFF', 'middle');
    } else {
        $o[] = $text(24, 110, $t('range'), 15, 500, $muted);
        $o[] = $box(246, 92, 120, 28, 14, $sun);
        $o[] = $text(306, 111, $t('sample'), 12, 700, $ink, 'middle');
        $cells = [
            [24, 138, 'sales', '$6,240', $t('orders'), $cobalt, '#FFFFFF'],
            [201, 138, 'discount', '$1,997', $t('share'), $wash, $ink],
            [24, 300, 'aov', '$48.75', $t('vs').' $41.20', $wash, $ink],
            [201, 300, 'units', '2.6', $t('vs').' 1.4', $wash, $ink],
        ];
        foreach ($cells as [$x, $y, $key, $value, $note, $bg, $fg]) {
            $o[] = $box($x, $y, 165, 150, 16, $bg);
            $o[] = $text($x + 14, $y + 32, $t($key), 13, 500, $fg);
            $o[] = $text($x + 14, $y + 84, $value, 30, 800, $fg);
            $o[] = $text($x + 14, $y + 122, $note, 10, 400, $fg);
        }
        foreach (['gifts', 'method', 'profit'] as $i => $key) {
            $y = 478 + $i * 66;
            $o[] = $box(24, $y, 342, 54, 12, '#FFFFFF', $line);
            $o[] = $text(42, $y + 33, $t($key), 15, 600);
            $o[] = $text(346, $y + 34, '+', 20, 500, $muted, 'end');
        }
    }
@endphp
<svg class="screen" data-screen="{{ $name }}" viewBox="0 0 390 800" xmlns="http://www.w3.org/2000/svg" direction="{{ $dir }}" font-family="Alexandria, 'Segoe UI', Tahoma, system-ui, sans-serif" role="img" aria-label="{{ $t('title') }}">{!! implode('', $o) !!}</svg>
