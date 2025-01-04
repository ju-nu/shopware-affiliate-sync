<?php

namespace JUNU\RealADCELL;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\PsrHandler;
use Monolog\Formatter\LineFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class LoggerFactory
 * Creates a Monolog logger that outputs to both console & file.
 */
class LoggerFactory
{
    public static function createLogger(string $name = 'app'): Logger
    {
        $log = new Logger($name);

        $dateFormat   = "Y-m-d H:i:s";
        $outputFormat = "[%datetime%] %channel%.%level_name%: %message% %context%\n";
        $formatter    = new LineFormatter($outputFormat, $dateFormat);

        // 1) Console Handler
        $consoleOutput  = new ConsoleOutput();
        $consoleHandler = new PsrHandler($consoleOutput);
        $consoleHandler->setFormatter($formatter);
        $log->pushHandler($consoleHandler);

        // 2) File Handler
        $logFilePath = $_ENV['LOG_FILE'] ?? 'logs/app.log';
        $fileHandler = new StreamHandler($logFilePath, Logger::DEBUG);
        $fileHandler->setFormatter($formatter);
        $log->pushHandler($fileHandler);

        return $log;
    }
}
