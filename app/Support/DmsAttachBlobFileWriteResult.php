<?php

namespace App\Support;

class DmsAttachBlobFileWriteResult
{
    public function __construct(
        private readonly string $path,
        private readonly int $size,
        private readonly string $sha256,
        private readonly bool $alreadyExists,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function alreadyExists(): bool
    {
        return $this->alreadyExists;
    }
}
