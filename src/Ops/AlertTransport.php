<?php

declare(strict_types=1);

namespace CW\Ops;

/** How an alert leaves CW (AlertDispatch; docs/decisions.md PF10). Each method throws when the message was not taken. */
interface AlertTransport
{
    /** POSTs $payload as JSON to $url (https only), within $timeoutSec. @param array<string, mixed> $payload */
    public function webhook(string $url, array $payload, int $timeoutSec): void;

    /** Hands a plain-text e-mail to the server's mail system. */
    public function email(string $to, string $subject, string $body): void;
}
