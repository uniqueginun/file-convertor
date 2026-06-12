<?php

namespace App\Support;

use RuntimeException;

class DmsAttachBlobFileWriter
{
    public function resolvePath(string $uuid): string
    {
        $basePath = rtrim((string) config('filesystems.blob_migration_base_path'), '/\\');

        return $basePath.DIRECTORY_SEPARATOR.$uuid;
    }

    public function write(string $uuid, string $binaryContent): DmsAttachBlobFileWriteResult
    {
        $path = $this->resolvePath($uuid);

        if (is_file($path)) {
            return new DmsAttachBlobFileWriteResult(
                path: $path,
                size: (int) filesize($path),
                sha256: (string) hash_file('sha256', $path),
                alreadyExists: true,
            );
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create migration directory [{$directory}].");
        }

        $temporaryPath = $path.'.'.uniqid('tmp', true);

        try {
            if (file_put_contents($temporaryPath, $binaryContent) === false) {
                throw new RuntimeException("Unable to write temporary file [{$temporaryPath}].");
            }

            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException("Unable to move temporary file to [{$path}].");
            }

            return new DmsAttachBlobFileWriteResult(
                path: $path,
                size: (int) filesize($path),
                sha256: (string) hash_file('sha256', $path),
                alreadyExists: false,
            );
        } catch (\Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw $exception;
        }
    }
}
