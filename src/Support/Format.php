<?php

declare(strict_types=1);

namespace NickWelsh\Cull\Support;

final class Format
{
    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) floor(log((float) $bytes, 1024.0));
        $i = max(1, min($i, count($units)));
        $val = $bytes / (1024 ** ($i));
        $unit = $units[$i - 1];

        return sprintf('%.2f %s', $val, $unit);
    }
}
