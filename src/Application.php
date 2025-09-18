<?php

declare(strict_types=1);

namespace NickWelsh\Cull;

use NickWelsh\Cull\Support\Format;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class Application
{
    public function run(?string $startDirectory = null): int
    {
        $root = $startDirectory !== null && $startDirectory !== '' && $startDirectory !== '0' ? $startDirectory : getcwd();

        // Parse flags
        $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
        if ($argv !== []) {
            array_shift($argv);
        }
        $argv = array_values(array_filter($argv, static fn ($v): bool => is_string($v)));
        $skipSizes = $this->argvHasFlag($argv, ['--no-size', '--no-sizes', '--skip-size', '--skip-sizes']);

        $scanner = new Scanner();
        [$items, $visited] = spin(fn (): array => $scanner->scanForDependencies((string) $root), 'Scanning directories…');
        if ($items === []) {
            info("No node_modules or vendor folders found under: {$root}");

            return 0;
        }

        if (! $skipSizes) {
            $sizer = new Sizer();
            $paths = array_column($items, 'path');
            $sizes = spin(fn (): array => $sizer->compute($paths), 'Measuring sizes…');
            foreach ($items as &$row) {
                $row['size'] = isset($sizes[$row['path']]) ? (int) $sizes[$row['path']] : 0;
            }
            unset($row);
            usort($items, fn (array $a, array $b): int => $b['size'] <=> $a['size']);
        }

        table(
            headers: ['#', 'Type', 'Path (relative)', 'Size'],
            rows: array_map(
                fn ($i, $row): array => [
                    (string) ($i + 1),
                    $row['type'],
                    $row['relative'],
                    Format::bytes($row['size']),
                ],
                array_keys($items),
                $items
            )
        );

        $options = [];
        foreach ($items as $i => $row) {
            $options[(string) $i] = sprintf('%s • %s • %s', $row['type'], $row['relative'], Format::bytes((int) $row['size']));
        }

        $selectedKeys = multisearch(
            label: 'Select folders to delete',
            options: fn (string $value): array => array_filter($options, fn ($k): bool => mb_strpos($k, $value) !== false),
            placeholder: 'E.g. my-project',
            scroll: 20,
            hint: 'Space = toggle, Enter = confirm, Ctrl+A = toggle all, Ctrl+C = cancel',
        );

        if ($selectedKeys === []) {
            warning('Nothing selected. Exiting.');

            return 0;
        }

        $selected = array_map(fn ($k): mixed => $items[(int) $k], $selectedKeys);

        $sumSizes = array_sum(array_column($selected, 'size'));
        $ok = confirm(
            label: 'Delete selected folders?',
            default: false,
            hint: 'This will permanently delete the directories above.'
        );
        if (! $ok) {
            warning('Cancelled.');

            return 0;
        }

        $deleter = new Deleter();
        $errors = [];
        progress('Deleting…', count($selected), function () use (&$errors, $deleter, $selected): void {
            $paths = array_column($selected, 'path');
            $errs = $deleter->deletePathsInBatches($paths, advance: static function (): void {});
            foreach ($errs as $e) {
                $errors[] = $e;
            }
        });

        $deletedBytes = 0;
        foreach ($selected as $row) {
            if (! is_dir($row['path'])) {
                $deletedBytes += (int) $row['size'];
            }
        }

        $freed = Format::bytes($deletedBytes);
        $planned = Format::bytes((int) $sumSizes);
        info("Freed: {$freed} (planned: {$planned})");

        if ($errors !== []) {
            error('Some items failed to delete:');
            table(['Path', 'Error'], array_map(fn (array $e): array => [$e['path'], $e['error']], $errors));
        }

        return $errors !== [] ? 1 : 0;
    }

    /**
     * @param  array<int,string>  $argv
     * @param  array<int,string>  $flags
     */
    private function argvHasFlag(array $argv, array $flags): bool
    {
        foreach ($argv as $arg) {
            if (in_array($arg, $flags, true)) {
                return true;
            }
        }

        return false;
    }
}
