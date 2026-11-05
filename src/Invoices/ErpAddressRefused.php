<?php

declare(strict_types=1);

namespace CW\Invoices;

/**
 * An ERPNext address the transport refuses to call (docs/decisions.md AK5): an address saved on Settings › APIs whose name does not lead to
 * one public internet address (a private, local or reserved one, or none). Nothing was sent. The message names no key or header.
 */
final class ErpAddressRefused extends \RuntimeException
{
}
