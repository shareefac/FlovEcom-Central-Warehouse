<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\Availability;
use CW\CwException;

/**
 * GET /v1/availability?variant_ids=1,2,3 (or variant_ids[]=1&variant_ids[]=2) — current values
 * for a cart pre-check; unknown variants come back as unlinked with version 0.
 */
final class AvailabilityController
{
    public const MAX_VARIANTS = 1000;

    public function index(Context $c): Response
    {
        $raw = $c->query()['variant_ids'] ?? null;
        $ids = is_array($raw) ? $raw : (is_string($raw) ? explode(',', $raw) : []);
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new CwException('bad_query', 'variant_ids must be a comma-separated list', 400, ['field' => 'variant_ids']);
            }
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            if (strlen($id) > 64 || preg_match('/^[\x21-\x7e]+$/', $id) !== 1) {
                throw new CwException('bad_query', 'each variant id must be 1-64 printable characters', 400, ['field' => 'variant_ids']);
            }
            $out[$id] = true;
        }
        if ($out === [] || count($out) > self::MAX_VARIANTS) {
            throw new CwException('bad_query', 'variant_ids must name 1-' . self::MAX_VARIANTS . ' variants', 400, ['field' => 'variant_ids']);
        }
        return Response::ok(['listings' => (new Availability($c->db))->forVariants($c->channel->id, array_map('strval', array_keys($out)))]);
    }
}
