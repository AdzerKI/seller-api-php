<?php

namespace Opotopo\SellerApi\Files;

interface FileSource
{
    public function source(): string;

    public function exists(): bool;

    public function name(): string;

    public function size(): int;

    public function modifiedAt(): int;

    public function mimeType(): string;

    /**
     * @return resource
     */
    public function stream();
}
