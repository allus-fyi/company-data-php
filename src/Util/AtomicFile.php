<?php

declare(strict_types=1);

namespace Allus\CompanyData\Util;

/**
 * Crash-safe atomic file writes.
 *
 * Each write goes to a temp file in the SAME directory, is fsync'd, then
 * atomically {@code rename()}-d into place; the containing directory is fsync'd
 * so the create/rename is durably recorded. A crash anywhere leaves the
 * destination as either the old file or the complete new one — never a truncated
 * partial. PHP 8.1+ has {@see \fsync()}.
 *
 * This is the single atomic-write discipline used by {@see \Allus\CompanyData\Crypto\BinaryHandle::save()},
 * the durable {@see \Allus\CompanyData\Pump\FileBuffer}, and its sequence file.
 */
final class AtomicFile
{
    /**
     * Write {@code $data} to {@code $path} crash-safely.
     *
     * @throws \RuntimeException on any I/O failure (the temp file is cleaned up).
     */
    public static function write(string $path, string $data): void
    {
        $dir = \dirname($path);
        $tmp = @tempnam($dir, '.tmp_');
        if ($tmp === false) {
            throw new \RuntimeException("could not create temp file in {$dir}");
        }
        try {
            $fh = @fopen($tmp, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("could not open temp file {$tmp}");
            }
            try {
                $written = @fwrite($fh, $data);
                if ($written === false || $written !== strlen($data)) {
                    throw new \RuntimeException("short write to {$tmp}");
                }
                @fflush($fh);
                // Durably flush the file's contents before the rename.
                @fsync($fh);
            } finally {
                @fclose($fh);
            }
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException("could not rename {$tmp} -> {$path}");
            }
            $tmp = null; // renamed away — don't unlink below
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
        // Durably record the rename in the directory entry.
        self::fsyncDir($dir);
    }

    /**
     * fsync a directory so a create/rename within it is durably recorded.
     *
     * Best-effort: some platforms can't open a directory for fsync — that is not
     * fatal (the file contents were already fsync'd).
     */
    public static function fsyncDir(string $path): void
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            return;
        }
        try {
            @fsync($fh);
        } finally {
            @fclose($fh);
        }
    }
}
