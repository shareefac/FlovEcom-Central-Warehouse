<?php

declare(strict_types=1);

namespace CW\Profit;

/** A request to Parcel2Go that got no HTTP answer (no connection, a timeout, an answer too large): CW's own words only. */
final class P2gHttpFailed extends \RuntimeException
{
}
