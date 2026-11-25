<?php

declare(strict_types=1);

namespace CW\Ai;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The only way CW's Claude reader reaches the network (CW-1182; docs/decisions.md AI18, the Codex final review of 86aebbd, H2): a PSR-18
 * client around the real one that sends a request ONLY to https://api.anthropic.com on port 443 (no user info), and never lets a redirect
 * through: a 3xx answer is a failure here, before the SDK's own redirect loop could replay the document (the SDK strips the key on a
 * cross-origin redirect but sends the body again). Anything else refused: nothing leaves the server.
 */
final class AnthropicOnly implements ClientInterface
{
    public const HOST = 'api.anthropic.com';

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $u = $request->getUri();
        if (strtolower($u->getScheme()) !== 'https' || strtolower($u->getHost()) !== self::HOST || !in_array($u->getPort(), [null, 443], true)
            || $u->getUserInfo() !== '') {
            throw self::refused($request, 'a request to anywhere but https://' . self::HOST . ' is never sent');
        }
        $response = $this->inner->sendRequest($request);
        $code = $response->getStatusCode();
        if ($code >= 300 && $code < 400) {
            $response->getBody()->close();
            throw self::refused($request, "the API answered {$code} (a redirect): never followed");
        }
        return $response;
    }

    /** Whether a failure the SDK reports came from this guard (its cause chain). */
    public static function isRefusal(\Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof AnthropicOnlyRefusal) {
                return true;
            }
        }
        return false;
    }

    private static function refused(RequestInterface $request, string $why): AnthropicOnlyRefusal
    {
        return new AnthropicOnlyRefusal($why, $request);
    }
}

