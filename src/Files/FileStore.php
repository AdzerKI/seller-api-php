<?php

namespace Opotopo\SellerApi\Files;

interface FileStore
{
    public function find(FileTarget $target): ?StoredFile;

    public function save(FileTarget $target, StoredFile $file): void;

    /**
     * @param  list<string>  $fileIds
     */
    public function forget(array $fileIds): void;
}
