<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Service-Klasse zur Kommunikation mit OpenAI (ChatGPT),
 *           inkl. Rate-Limit-Handling und Rückgabe false bei Fehlschlag.
 */

namespace JUNU\ShopwareAffiliateSync\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

final class OpenAiService
{
    private Client $client;
    private LoggerInterface $logger;
    private string $openAiKey;
    private string $model;

    /**
     * Wartezeiten für die Retry-Logik bei 429 (Rate Limit).
     * (z. B. 3 Versuche: Warte 30s, 60s, 120s)
     */
    private array $backoffWaitSeconds = [30, 60, 120];

    /**
     * Konstruktor
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger   = $logger;
        $this->openAiKey= $_ENV['OPENAI_API_KEY'] ?? '';
        $this->model    = $_ENV['OPENAI_MODEL']   ?? 'gpt-4o-mini';

        $this->client = new Client([
            'base_uri' => 'https://api.openai.com',
            'timeout'  => 30,
        ]);
    }

    /**
     * Umschreibt die Produktbeschreibung (Deutsch), ohne den Titel zu wiederholen.
     * Falls OpenAI nicht funktioniert, wird "false" zurückgegeben.
     */
    public function rewriteDescription(string $title, string $description): bool|string
    {
        // Wenn beides leer, ergibt das keinen Sinn
        if (empty(\trim($title)) && empty(\trim($description))) {
            return '';
        }

        $prompt =
            "Bitte schreibe eine deutsche Produktbeschreibung," .
            " ohne den Produkt-Titel zu wiederholen. " .
            "Nutze nur diese vorhandenen Texte:\n\n" .
            "Beschreibung:\n" . $description . "\n\n" .
            "Produktname:\n" . $title . "\n\n" .
            "Schreibe sie conversionstark, ansprechend und positiv in deutscher Sprache.";

        // JSON-Payload für ChatGPT
        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 400,
        ];

        // Wir nutzen unsere zentrale Hilfsmethode
        $result = $this->postChatCompletion($payload);

        // Falls "false" => Fehler (z. B. Rate limit), sonst trimmen
        return $result === false ? false : \trim($result);
    }

    /**
     * Ermittelt die passendste Kategorie.
     * Rückgabe: string|false (false bei Fehler oder Rate Limit).
     */
    public function bestCategory(
        string $title,
        string $description,
        string $csvCategory,
        array $shopwareCategoryNames
    ): bool|string {
        if (empty($shopwareCategoryNames)) {
            return false; 
        }

        var_dump($categoryList);
        die();

        $categoryList = "- " . \implode("\n- ", $shopwareCategoryNames);
        $prompt = "Wir haben ein Produkt:\n" .
                  "- Titel: {$title}\n" .
                  "- Beschreibung: {$description}\n" .
                  "- CSV-Kategorie: {$csvCategory}\n\n" .
                  "Vorhandene Kategorien:\n{$categoryList}\n\n" .
                  "Welche EINE Kategorie passt am besten? Antworte mit dem exakten Namen aus obiger Liste.";

        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 50,
        ];

        $result = $this->postChatCompletion($payload);
        return $result === false ? false : \trim($result);
    }

    /**
     * Ermittelt die passende Lieferzeit.
     * Rückgabe: string|false (false bei Fehler).
     */
    public function bestDeliveryTime(
        string $csvDeliveryTime,
        array $shopwareDeliveryTimeNames
    ): bool|string {
        if (empty($shopwareDeliveryTimeNames)) {
            return false;
        }

        $dtList = "- " . \implode("\n- ", $shopwareDeliveryTimeNames);
        $prompt = "Wir haben ein Produkt mit CSV-Lieferzeit: '{$csvDeliveryTime}'.\n" .
                  "Es gibt folgende Shopware-Lieferzeiten:\n{$dtList}\n\n" .
                  "Welche passt am besten? Antworte mit dem exakten Namen.";

        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 50,
        ];

        $result = $this->postChatCompletion($payload);
        return $result === false ? false : \trim($result);
    }

    /**
     * -------------------------------------------------------
     * PRIVATE HILFSMETHODE:
     * Ruft /v1/chat/completions auf, mit Retry bei 429-Fehler.
     * Gibt einen String zurück oder "false" bei Fehlschlag.
     * -------------------------------------------------------
     */
    private function postChatCompletion(array $jsonPayload): bool|string
    {
        $maxAttempts = \count($this->backoffWaitSeconds) + 1;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->client->post('/v1/chat/completions', [
                    'headers' => [
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                        'Authorization' => "Bearer {$this->openAiKey}",
                    ],
                    'json' => $jsonPayload,
                ]);

                $json = \json_decode($response->getBody()->getContents(), true);
                // Text aus dem "content"-Feld
                return $json['choices'][0]['message']['content'] ?? '';
            } catch (ClientException $e) {
                // 4xx - check if 429
                $code = $e->getResponse()?->getStatusCode() ?? 0;
                if ($code === 429) {
                    // Rate Limit
                    if ($attempt < $maxAttempts) {
                        $waitSec = $this->backoffWaitSeconds[$attempt - 1] ?? 30;
                        $this->logger->warning(sprintf(
                            "OpenAI Rate-Limit (429). Versuch %d/%d. Warte %d Sekunden...",
                            $attempt,
                            $maxAttempts,
                            $waitSec
                        ));
                        \sleep($waitSec);
                        continue;
                    }
                    // Letzter Versuch => Abbruch
                    $this->logger->error("OpenAI 429: Alle Versuche aufgebraucht => gebe false zurück.");
                    return false;
                } else {
                    // anderer Fehler => Abbruch
                    $this->logger->error("OpenAI error {$code}: {$e->getMessage()}");
                    return false;
                }
            } catch (GuzzleException $e) {
                // Netzwerk-/Timeout-Fehler, direkt abbrechen
                $this->logger->error("OpenAI GuzzleException: " . $e->getMessage());
                return false;
            } catch (\Throwable $t) {
                // Sonstiger Fehler, abbrechen
                $this->logger->error("OpenAI unknown error: " . $t->getMessage());
                return false;
            }
        }

        // Falls wir aus der Schleife fliegen, ebenfalls false
        return false;
    }
}
