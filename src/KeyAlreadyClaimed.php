<?php

declare(strict_types=1);

namespace CW;

/** @internal Idempotency: the key was committed by a concurrent duplicate; read its stored result. */
final class KeyAlreadyClaimed extends \RuntimeException
{
}
