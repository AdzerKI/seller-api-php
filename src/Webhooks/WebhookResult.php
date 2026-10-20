<?php

namespace Opotopo\SellerApi\Webhooks;

enum WebhookResult
{
    case Handled;
    case Ignored;
    case Unauthorized;
    case InFlight;

    public function status(): int
    {
        return match ($this) {
            self::Handled, self::Ignored => 204,
            self::Unauthorized => 401,
            self::InFlight => 409,
        };
    }
}
