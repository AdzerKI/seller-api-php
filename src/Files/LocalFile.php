<?php

namespace Opotopo\SellerApi\Files;

final class LocalFile implements FileSource
{
    private const SOURCE = 'file:';

    public function __construct(
        private readonly string $path,
        private readonly string $name,
        private readonly string $mimeType,
    ) {}

    public function source(): string
    {
        return self::SOURCE.$this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path) && is_readable($this->path);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function size(): int
    {
        return (int) filesize($this->path);
    }

    public function modifiedAt(): int
    {
        return (int) filemtime($this->path);
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function stream()
    {
        return fopen($this->path, 'rb');
    }
}
