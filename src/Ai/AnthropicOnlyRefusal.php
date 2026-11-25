<?php

declare(strict_types=1);

namespace CW\Ai;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** What AnthropicOnly throws: a PSR-18 client failure (the SDK reports it as a connection failure; Claude reads it as `redirect`). */
final class AnthropicOnlyRefusal extends \RuntimeException implements ClientExceptionInterface
{
    public function __construct(string $message, public readonly RequestInterface $request)
    {
        parent::__construct($message);
    }
}
