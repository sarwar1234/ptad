<?php

declare(strict_types=1);

namespace PtadLoader\Support;

/**
 * ============================================================
 * PTAD — Exceptions Log
 * ============================================================
 * Per Loader Spec B9: every row the loader cannot cleanly parse
 * is written here with the reason — NEVER silently skipped.
 * One CSV file per module run, in loader/logs/.
 * ============================================================
 */
final class ExceptionsLog
{
    private string $path;
    /** @var resource */
    private $handle;
    private int $count = 0;

    public function __construct(string $moduleCode)
    {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $timestamp = date('Ymd_His');
        $this->path = $dir . "/{$moduleCode}_exceptions_{$timestamp}.csv";

        $this->handle = fopen($this->path, 'w');
        fputcsv($this->handle, ['sheet', 'row_number', 'hs_code_raw', 'reason']);
    }

    public function record(string $sheet, int $rowNumber, string $hsCodeRaw, string $reason): void
    {
        fputcsv($this->handle, [$sheet, $rowNumber, $hsCodeRaw, $reason]);
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): void
    {
        fclose($this->handle);
    }
}
