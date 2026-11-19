<?php

namespace App\Notifications;

use RuntimeException;

final class DeliveryFailure extends RuntimeException
{
    public function __construct(string $reason, public readonly bool $retry = false, public readonly bool $uncertain = false)
    {
        parent::__construct($reason);
    }
}
