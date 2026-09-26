<?php

declare(strict_types=1);

namespace CW;

/**
 * @internal Thrown inside an idempotent operation when a concurrent transaction created the row
 * it was about to insert (e.g. two first calls for one order_ref). Idempotency::run() rolls back
 * and runs the whole operation again, which then finds the row and follows the state machine.
 */
final class RetryOperation extends \RuntimeException
{
}
