<?php

namespace JUNU\RealADCELL;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class LoggerFactory
{
    /**
     * Creates a Logger instance that writes logs to both:
     *  - A log file (LOG_FILE env or logs/app.log)
     *  - The console (stdout)
     *
     * @param string $name The name/channel of the logger
     * @return Logger
     */
    public static function createLogger(string $name = 'app'): Logger
    {
        $logger = new Logger($name);

        // Format: [2025-01-04 12:00:00] app.DEBUG: message
        $dateFormat   = "Y-m-d H:i:s";
        $outputFormat = "[%datetime%] %channel%.%level_name%: %message% %context%\n";
        $formatter    = new LineFormatter($outputFormat, $dateFormat);

        // 1) File Handler
        $logFilePath = $_ENV['LOG_FILE'] ?? 'logs/app.log';
        $fileHandler = new StreamHandler($logFilePath, Logger::DEBUG);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);

        // 2) Console Handler (writes to php://stdout)
        $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $consoleHandler->setFormatter($formatter);
        $logger->pushHandler($consoleHandler);

        return $logger;
    }
}
