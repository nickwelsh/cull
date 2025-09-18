<?php

declare(strict_types=1);

namespace NickWelsh\Cull;

use FilesystemIterator;
use NickWelsh\Cull\Support\Platform;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

final class Sizer
{
    /**
     * @param  list<string>  $paths
     *
     * @phpstan-return array<string,int>
     */
    public function compute(array $paths): array
    {
        if ($paths === []) {
            /** @var array<string,int> $empty */
            $empty = [];

            return $empty;
        }
        try {
            return Platform::isWindows() ? $this->psSizes($paths) : $this->duSizes($paths);
        } catch (Throwable) {
            $out = [];
            foreach ($paths as $p) {
                $out[$p] = $this->dirSize($p);
            }

            return $out;
        }
    }

    /**
     * @param  list<string>  $paths
     * @param  positive-int  $chunkSize
     *
     * @phpstan-return array<string,int>
     */
    private function duSizes(array $paths, ?int $jobs = null, int $chunkSize = 64): array
    {
        $jobs ??= max(2, Platform::cpuCount());
        $chunks = array_chunk($paths, $chunkSize);
        /** @var non-empty-array<int, array{0:Process,1:list<string>}>|array{} $running */
        $running = [];
        /** @var array<string,int> $results */
        $results = [];

        /** @param list<string> $batch */
        $launch = function (array $batch) use (&$running): void {
            $args = [];
            foreach ($batch as $s) {
                /** @var string $s */
                $args[] = escapeshellarg($s);
            }
            $cmd = 'du -skx -- '.implode(' ', $args);
            $p = Process::fromShellCommandline($cmd);
            $p->setTimeout(null);
            $p->start();
            $stringBatch = array_values($batch);
            /** @var list<string> $stringBatch */
            $running[] = [$p, $stringBatch];
        };

        foreach ($chunks as $chunk) {
            while (count($running) >= $jobs) {
                $this->waitOneDu($running, $results);
            }
            $launch($chunk);
        }
        while ($running) {
            $this->waitOneDu($running, $results);
        }

        /** @var array<string,int> $final */
        $final = [];
        foreach ($results as $path => $kib) {
            $final[$path] = ((int) $kib) * 1024;
        }

        return $final;
    }

    /**
     * @param  non-empty-array<int, array{0:Process,1:list<string>}>|array{}  $running
     * @param  array<string,int>  $results
     */
    private function waitOneDu(array &$running, array &$results): void
    {
        $shifted = array_shift($running);
        if ($shifted === null) {
            return;
        }
        [$proc, $batch] = $shifted;
        $proc->wait();
        $out = $proc->getOutput();
        $lines = preg_split('/\R/', mb_trim((string) $out));
        foreach (($lines === false ? [] : $lines) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if ($parts !== false && count($parts) === 2) {
                $kib = $parts[0];
                $path = $parts[1];
                $results[$path] = (int) $kib;
            }
        }
    }

    /**
     * @param  list<string>  $paths
     *
     * @phpstan-return array<string,int>
     */
    private function psSizes(array $paths, ?int $jobs = null): array
    {
        $jobs ??= max(2, Platform::cpuCount());
        /** @var array<int, array{0:Process,1:string}> $running */
        $running = [];
        /** @var array<string,int> $results */
        $results = [];

        $launch = function (string $path) use (&$running): void {
            $tpl = 'powershell -NoProfile -Command '.
                '"$p=\''.'%s'.'\'; $s=(Get-ChildItem -LiteralPath $p -Recurse -File -Force -ErrorAction SilentlyContinue | '.
                'Measure-Object -Property Length -Sum).Sum; if($s -eq $null){$s=0}; Write-Output $s"';
            $cmd = sprintf($tpl, addcslashes($path, "'"));
            $p = Process::fromShellCommandline($cmd);
            $p->setTimeout(null);
            $p->start();
            $running[] = [$p, $path];
        };

        $i = 0;
        $n = count($paths);
        while ($i < $n || $running) {
            while ($i < $n && count($running) < $jobs) {
                $launch($paths[$i]);
                $i++;
            }
            if ($running !== []) {
                [$proc, $path] = array_shift($running);
                $proc->wait();
                $out = mb_trim((string) $proc->getOutput());
                $results[$path] = (int) $out;
            }
        }

        return $results;
    }

    private function dirSize(string $path): int
    {
        $bytes = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $path,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
                ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $file) { /** @var SplFileInfo $file */
                if ($file->isLink()) {
                    continue;
                }
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (Throwable) {
        }

        return $bytes;
    }
}
