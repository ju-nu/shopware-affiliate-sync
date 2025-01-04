<?php

namespace JUNU\RealADCELL;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;

/**
 * Class EnvLoader
 * Loads environment variables from .env
 */
class EnvLoader
{
    public static function loadEnv(string $path)
    {
        try {
            $dotenv = Dotenv::createImmutable($path);
            $dotenv->load();
        } catch (InvalidPathException $e) {
            // If no .env is found, just continue or log a warning if desired
        }
    }
}
