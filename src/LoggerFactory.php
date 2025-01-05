<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Erzeugt Monolog-Logger mit File- und Console-Handler.
 */

namespace JUNU\ShopwareAffiliateSync;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class LoggerFactory
{
    public static function createLogger(string $name = 'app'): Logger
    {
        $logger = new Logger($name);

        // Beispiel-Format
        $dateFormat   = "Y-m-d H:i:s";
        $outputFormat = "[%datetime%] %channel%.%level_name%: %message% %context%\n";

        // Der dritte Parameter in LineFormatter ist "$allowInlineLineBreaks" (false/true)
        // der vierte "$ignoreEmptyContextAndExtra".
        // Danach können wir separate Methoden nutzen, um das JSON-Encoding zu beeinflussen.
        $formatter = new LineFormatter($outputFormat, $dateFormat, false, true);
        
        // Wichtig, um Umlaute nicht als \uXXXX zu escapen und Sonderzeichen beizubehalten
        //$formatter->setJsonEncodeOptions(JSON_UNESCAPED_UNICODE);

        // File Handler
        $logFilePath = $_ENV['LOG_FILE'] ?? 'logs/app.log';

        if (file_exists($logFilePath)) {
            @unlink($logFilePath);
        }
        
        $fileHandler = new StreamHandler($logFilePath, Logger::DEBUG);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);

        // Optional: Konsolen-Handler (falls erwünscht)
        $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $consoleHandler->setFormatter($formatter);
        $logger->pushHandler($consoleHandler);

        return $logger;
    }
}