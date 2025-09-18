<?php

declare(strict_types=1);

namespace NickWelsh\Cull;

final class Cull
{
    public static function run(?string $startDirectory = null): int
    {
        return (new Application())->run($startDirectory);
    }
}
