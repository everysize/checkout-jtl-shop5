<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Import;

/**
 * Thrown for an everysize order that cannot be imported — missing fields, an
 * unknown SKU, an unknown country. Sync collects these per order and retries
 * in the next run; the order stays in the everysize open list.
 */
class ImportException extends \RuntimeException
{
}
