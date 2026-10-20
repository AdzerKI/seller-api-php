<?php

namespace Opotopo\SellerApi;

final class ClientConfig
{
    public function __construct(
        public readonly string $url,
        public readonly string $key,
        public readonly ?string $organizationId = null,
        public readonly bool $publish = true,
        public readonly int $retryTimes = 3,
        public readonly int $retrySleepMs = 1000,
    ) {}
}
