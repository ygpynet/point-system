<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Exception;

final class UploadValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $detail,
        public readonly int $status = 422,
    ) {
        parent::__construct($detail);
    }
}
