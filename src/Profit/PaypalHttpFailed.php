<?php

declare(strict_types=1);

namespace CW\Profit;

/** A PayPal request that got no HTTP answer (PaypalHttp): its message is CW's own words, never the request or the key. */
final class PaypalHttpFailed extends \RuntimeException
{
}
