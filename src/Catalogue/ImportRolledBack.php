<?php

declare(strict_types=1);

namespace CW\Catalogue;

/** @internal ItemCardCsv's way out of its transaction after a dry run or a refused row: everything is rolled back. */
final class ImportRolledBack extends \RuntimeException
{
}
