<?php

namespace Opotopo\SellerApi\Files;

use Closure;
use Opotopo\SellerApi\ApiException;
use Opotopo\SellerApi\Catalog\ItemFailure;
use Opotopo\SellerApi\SellerApiClient;
use Psr\Http\Message\StreamFactoryInterface;

final class FileUploader
{
    public const MAIN = 'MAIN';

    public const PROJECT = 'PROJECT';

    private const ETAG_HEADER = 'ETag';

    private const CONTENT_LENGTH_HEADER = 'Content-Length';

    private const CHECKSUM = 'sha256';

    public function __construct(
        private readonly SellerApiClient $client,
        private readonly FileStore $store,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function upload(FileSource $file, string $gallery, string $categoryId): string
    {
        $target = FileTarget::of($file, $gallery, $categoryId);
        $known = $this->store->find($target);
        $size = $file->size();
        $modifiedAt = $file->modifiedAt();
        if ($known !== null && $known->size === $size && $known->modifiedAt === $modifiedAt) {
            return $known->fileId;
        }
        $checksum = self::checksum($file);
        $remember = fn (string $fileId) => $this->store->save(
            $target,
            new StoredFile($file->source(), $size, $modifiedAt, $checksum, $fileId),
        );
        if ($known !== null && $known->checksum === $checksum) {
            $remember($known->fileId);

            return $known->fileId;
        }

        return $this->send($file, $gallery, $categoryId, $size, $remember);
    }

    /**
     * @param  list<string>  $fileIds
     */
    public function forget(array $fileIds): void
    {
        $this->store->forget($fileIds);
    }

    private function send(FileSource $file, string $gallery, string $categoryId, int $size, Closure $remember): string
    {
        $contentType = $file->mimeType();
        $initiated = $this->accepted($file, fn () => $this->client->initiateUpload([
            'gallery' => $gallery,
            'categoryId' => $categoryId,
            'filename' => $file->name(),
            'contentType' => $contentType,
            'size' => $size,
        ]));
        $headers = [SellerApiClient::CONTENT_TYPE_HEADER => $contentType, ...($initiated['requiredHeaders'] ?? [])];
        $stream = $file->stream();
        $parts = [];
        try {
            if (! empty($initiated['singleUrl'])) {
                $this->client->put(
                    $initiated['singleUrl'],
                    [...$headers, self::CONTENT_LENGTH_HEADER => (string) $size],
                    $this->streams->createStreamFromResource($stream),
                );
            } else {
                $urls = $initiated['partUrls'] ?? [];
                usort($urls, fn (array $left, array $right) => $left['partNumber'] <=> $right['partNumber']);
                foreach ($urls as $part) {
                    $response = $this->client->put($part['url'], $headers, self::read($stream, (int) $initiated['partSize']));
                    $parts[] = ['partNumber' => $part['partNumber'], 'etag' => $response->getHeaderLine(self::ETAG_HEADER)];
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $remember($initiated['fileId']);
        $this->accepted($file, fn () => $this->client->completeUpload($initiated['fileId'], $parts));

        return $initiated['fileId'];
    }

    private function accepted(FileSource $file, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ApiException $failure) {
            if (! $failure->rejected()) {
                throw $failure;
            }

            throw ItemFailure::fromApi($failure, [ItemFailure::FILE_DETAIL => $file->source()]);
        }
    }

    /**
     * @param  resource  $stream
     */
    private static function read($stream, int $length): string
    {
        $chunk = '';
        while (strlen($chunk) < $length && ! feof($stream)) {
            $chunk .= fread($stream, $length - strlen($chunk));
        }

        return $chunk;
    }

    private static function checksum(FileSource $file): string
    {
        $stream = $file->stream();
        try {
            $context = hash_init(self::CHECKSUM);
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
