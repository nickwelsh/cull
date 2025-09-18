<?php

declare(strict_types=1);

namespace NickWelsh\Cull;

use SplQueue;

final class Scanner
{
    /** @return array{0: array<int, array{path:string,relative:string,type:string,size:int}>, 1: int} */
    public function scanForDependencies(string $root): array
    {
        $results = [];
        $queue = new SplQueue();
        $queue->enqueue($root);
        $visited = 0;

        while (! $queue->isEmpty()) {
            /** @var string $dir */
            $dir = $queue->dequeue();
            $visited++;

            if (is_link($dir)) {
                continue;
            }

            $isProjectRoot = is_file($dir.DIRECTORY_SEPARATOR.'package.json') || is_file($dir.DIRECTORY_SEPARATOR.'composer.json');
            if ($isProjectRoot) {
                foreach (['node_modules', 'vendor'] as $depDir) {
                    $full = $dir.DIRECTORY_SEPARATOR.$depDir;
                    if (is_dir($full) && ! is_link($full)) {
                        $results[] = [
                            'path' => $full,
                            'relative' => mb_ltrim(str_replace($root, '', $full), DIRECTORY_SEPARATOR),
                            'type' => $depDir,
                            'size' => 0,
                        ];
                    }
                }

                continue;
            }

            $dh = @opendir($dir);
            if ($dh === false) {
                continue;
            }
            while (($entry = readdir($dh)) !== false) {
                if ($entry === '.') {
                    continue;
                }
                if ($entry === '..') {
                    continue;
                }
                $full = $dir.DIRECTORY_SEPARATOR.$entry;
                if (! is_dir($full)) {
                    continue;
                }
                if (is_link($full)) {
                    continue;
                }
                if ($entry === 'node_modules') {
                    continue;
                }
                if ($entry === 'vendor') {
                    continue;
                }
                $queue->enqueue($full);
            }
            closedir($dh);
        }

        return [$results, $visited];
    }
}
