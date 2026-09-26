<?php

declare(strict_types=1);

namespace CW;

/** Missing or malformed configuration. Messages never contain secret values. */
final class ConfigException extends \RuntimeException
{
}
