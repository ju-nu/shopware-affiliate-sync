<?php

namespace JUNU\RealADCELL\Service;

use Ramsey\Uuid\Uuid;

/**
 * Class UuidService
 * Generates a 32-character hex UUID using ramsey/uuid, removing dashes.
 */
class UuidService
{
    public static function generate(): string
    {
        // Standard ramsey/uuid v4 => 36 characters with dashes
        // We'll remove the dashes to produce a 32-char hex string: ^[0-9a-f]{32}$
        $uuid = Uuid::uuid4()->toString();       // e.g. "6c8a5744-45d2-4bab-a3db-6ef3a0e4abcd"
        return str_replace('-', '', $uuid);      // e.g. "6c8a574445d24baba3db6ef3a0e4abcd"
    }
}
