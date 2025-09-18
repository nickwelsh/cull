<?php

declare(strict_types=1);

namespace NickWelsh\Cull;

use NickWelsh\Cull\Support\Platform;
use Symfony\Component\Process\Process;

final class Deleter
{
    /**
     * @param  list<string>  $paths
     * @param  callable  $advance  called once per path to advance progress bar
     * @return list<array{path:string,error:string}>
     */
    public function deletePathsInBatches(array $paths, callable $advance): array
    {
        /** @var list<array{path:string,error:string}> $errors */
        $errors = [];
        if ($paths === []) {
            return $errors;
        }

        $jobs = max(2, Platform::cpuCount());
        $chunkSize = 64;
        /** @var non-empty-array<int, array{0:Process,1:list<string>}>|array{} $running */
        $running = [];

        /** @param list<string> $batch */
        $launch = function (array $batch) use (&$running): void {
            if (Platform::isWindows()) {
                $quoted = [];
                foreach ($batch as $p0) {
                    /** @var string $p0 */
                    $quoted[] = "'".str_replace("'", "''", $p0)."'";
                }
                $joined = implode(',', $quoted);
                $script = "\$paths=@($joined); Remove-Item -LiteralPath \$paths -Recurse -Force -ErrorAction SilentlyContinue";
                $cmd = 'powershell -NoProfile -Command "'.$script.'"';
                $p = Process::fromShellCommandline($cmd);
            } else {
                $args = [];
                foreach ($batch as $s) {
                    /** @var string $s */
                    $args[] = escapeshellarg($s);
                }
                $cmd = 'rm -rf -- '.implode(' ', $args);
                $p = Process::fromShellCommandline($cmd);
            }
            $p->setTimeout(null);
            $p->start();
            $stringBatch = array_values($batch);
            /** @var list<string> $stringBatch */
            $running[] = [$p, $stringBatch];
        };

        /** @var list<list<string>> $batches */
        $batches = array_chunk($paths, $chunkSize);
        foreach ($batches as $batch) {
            while (count($running) >= $jobs) {
                $this->waitOneDelete($running, $advance, $errors);
            }
            $launch($batch);
        }
        while ($running !== []) {
            $this->waitOneDelete($running, $advance, $errors);
        }

        return $errors;
    }

    /**
     * @param  non-empty-array<int, array{0:Process,1:list<string>}>|array{}  $running
     * @param  list<array{path:string,error:string}>  $errors
     */
    private function waitOneDelete(array &$running, callable $advance, array &$errors): void
    {
        $shifted = array_shift($running);
        if ($shifted === null) {
            return;
        }
        [$proc, $batch] = $shifted;
        $proc->wait();
        $ok = $proc->getExitCode() === 0;
        foreach ($batch as $path) {
            if (! ($ok && ! is_dir($path))) {
                $errors[] = ['path' => $path, 'error' => 'Failed to delete'];
            }
            $advance();
        }
    }
}
