<?php

declare(strict_types=1);

namespace CW\Files;

/**
 * A storage backend could not do what was asked: `sha_mismatch` (the content is not the key), `collision` (the key is
 * stored with other content: corruption or tampering), `missing` (no such key), `io` (the file system refused).
 * FileStore turns these into CwExceptions for its callers.
 */
final class FileStoreException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
