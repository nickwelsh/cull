<?php

declare(strict_types=1);

namespace NickWelsh\Cull\Support;

use const PHP_OS_FAMILY;

final class Platform
{
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function cpuCount(): int
    {
        $proc = @shell_exec('nproc 2>/dev/null');
        if (($n = mb_trim((string) $proc)) !== '') {
            return (int) $n;
        }
        $mac = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if (($n = mb_trim((string) $mac)) !== '') {
            return (int) $n;
        }
        if (($n = getenv('NUMBER_OF_PROCESSORS'))) {
            return (int) $n;
        }

        return 4;
    }
}
