<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Generiert 32-stellige UUIDs (ohne Bindestriche) auf Basis von ramsey/uuid.
 */

namespace JUNU\RealADCELL\Service;

use Ramsey\Uuid\Uuid;

final class UuidService
{
    /**
     * Erzeugt eine 32-stellige hex-UUID (Version 4).
     */
    public static function generate(): string
    {
        $uuid = Uuid::uuid4()->toString(); // e.g. 36 chars mit Bindestrichen
        return \str_replace('-', '', $uuid); // 32 hex-chars
    }
}
