<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Erzeugt Monolog-Logger mit File- und Console-Handler.
 */

namespace JUNU\RealADCELL;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class LoggerFactory
{
    /**
     * Erzeugt einen Logger, der sowohl in ein Logfile als auch auf die Konsole schreibt.
     *
     * @param string $name Name/Channel des Loggers
     * @return Logger
     */
    public static function createLogger(string $name = 'app'): Logger
    {
        $logger = new Logger($name);

        // Format: [YYYY-mm-dd HH:ii:ss] channel.LEVEL: message
        $dateFormat   = "Y-m-d H:i:s";
        $outputFormat = "[%datetime%] %channel%.%level_name%: %message% %context%\n";
        $formatter    = new LineFormatter($outputFormat, $dateFormat);

        // File Handler
        $logFilePath = $_ENV['LOG_FILE'] ?? 'logs/app.log';
        if (file_exists($logFilePath)) {
            unlink($logFilePath);
        }
        $fileHandler = new StreamHandler($logFilePath, Logger::DEBUG);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);

        // Console Handler
        $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $consoleHandler->setFormatter($formatter);
        $logger->pushHandler($consoleHandler);

        return $logger;
    }
}
