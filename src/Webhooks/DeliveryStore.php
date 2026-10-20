<?php

namespace Opotopo\SellerApi\Webhooks;

interface DeliveryStore
{
    public function acquire(string $webhookId): bool;

    public function release(string $webhookId): void;

    public function processed(string $webhookId): bool;

    public function markProcessed(string $webhookId): void;
}
