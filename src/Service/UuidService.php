<?php

namespace JUNU\RealADCELL\Service;

use Ramsey\Uuid\Uuid;

/**
 * Class UuidService
 * Generates a 32-char hex string (no dashes) from a v4 UUID.
 */
class UuidService
{
    public static function generate(): string
    {
        // ramsey/uuid v4 => 36 chars with dashes, remove them => 32 hex
        $uuid = Uuid::uuid4()->toString(); 
        return str_replace('-', '', $uuid);
    }
}
