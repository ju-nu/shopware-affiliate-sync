<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Lädt Umgebungsvariablen aus einer .env Datei.
 */

namespace JUNU\RealADCELL;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;

final class EnvLoader
{
    /**
     * Lädt die .env (falls vorhanden) aus dem angegebenen Pfad.
     */
    public static function loadEnv(string $path): void
    {
        try {
            $dotenv = Dotenv::createImmutable($path);
            $dotenv->load();
        } catch (InvalidPathException $e) {
            // Falls keine .env gefunden, hier optional Warnung ausgeben
        }
    }
}
