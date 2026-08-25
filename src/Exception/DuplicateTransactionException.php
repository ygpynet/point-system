<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Exception;

/**
 * Thrown when a points transaction insert is rejected by the dedupe_key unique
 * index — i.e. a duplicate credit slipped past the existence guard under a
 * concurrent / retried dispatch.
 *
 * It exists so {@see \Ramon\PointSystem\Repository\PointsRepository::award()}
 * can roll the whole DB transaction back (undoing the balance/daily-cap
 * increment) instead of committing an orphaned balance bump with no ledger row.
 */
class DuplicateTransactionException extends \RuntimeException
{
}
