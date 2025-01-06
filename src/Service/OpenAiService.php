<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Service-Klasse zur Kommunikation mit OpenAI (ChatGPT),
 *           inklusive Rate-Limit-Handling und Retries.
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
     * Definiert, wie oft wir bei 429 (Rate Limit) erneut versuchen und wie lange wir warten.
     * z. B. bei 3 Versuchen => Wartezeiten 30s, 60s, 120s.
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
     * Umschreibt die Produktbeschreibung auf Deutsch, ohne den Produkt-Titel zu wiederholen.
     * Falls durch zu viele Requests ein 429 kommt, versuchen wir (standardmäßig) bis zu 3 Mal,
     * mit steigenden Wartezeiten.
     */
    public function rewriteDescription(string $title, string $description): string
    {
        // Wenn nichts drin steht, gleich zurück
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

        // Fallback für den Fall, dass alle Versuche scheitern => wir nutzen dann einfach den Originaltext
        $fallbackText = $description;

        // Request-Payload für ChatGPT
        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 400,
        ];

        // Unser generischer Chat-Aufruf mit Retry/Backoff:
        return $this->postChatCompletion($payload, $fallbackText);
    }

    /**
     * Bestimmt die passendste Kategorie aus einer vorhandenen Liste (shopwareCategoryNames),
     * basierend auf Titel, Beschreibung und csvCategory. 
     * Bei Rate-Limit-Fehlern wird bis zu 3 Mal erneut versucht, bevor wir mit null abbrechen.
     */
    public function bestCategory(
        string $title,
        string $description,
        string $csvCategory,
        array $shopwareCategoryNames
    ): ?string {
        if (empty($shopwareCategoryNames)) {
            return null;
        }

        $categoryList = "- " . \implode("\n- ", $shopwareCategoryNames);
        $prompt = "Wir haben ein Produkt:\n" .
                  "- Titel: {$title}\n" .
                  "- Beschreibung: {$description}\n" .
                  "- CSV-Kategorie: {$csvCategory}\n\n" .
                  "Vorhandene Kategorien:\n{$categoryList}\n\n" .
                  "Welche EINE Kategorie passt am besten? Antworte mit dem exakten Namen aus obiger Liste.";

        // Fallback => null, wenn gar nichts klappt
        $fallbackText = null;

        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 50,
        ];

        $result = $this->postChatCompletion($payload, $fallbackText);
        // Wenn postChatCompletion scheitert, kommt Fallback (= null) zurück
        $best = \trim($result ?? '');
        return $best !== '' ? $best : null;
    }

    /**
     * Ermittelt die passende Lieferzeit (bestDeliveryTime) aus einer bestehenden Liste
     * an Shopware-Lieferzeiten. 
     * Bei Rate Limit-Fehlern wird ebenfalls mehrmals versucht.
     */
    public function bestDeliveryTime(
        string $csvDeliveryTime,
        array $shopwareDeliveryTimeNames
    ): ?string {
        if (empty($shopwareDeliveryTimeNames)) {
            return null;
        }

        $dtList = "- " . \implode("\n- ", $shopwareDeliveryTimeNames);
        $prompt = "Wir haben ein Produkt mit CSV-Lieferzeit: '{$csvDeliveryTime}'.\n" .
                  "Es gibt folgende Shopware-Lieferzeiten:\n{$dtList}\n\n" .
                  "Welche passt am besten? Antworte mit dem exakten Namen.";

        $fallbackText = null;

        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 50,
        ];

        $result = $this->postChatCompletion($payload, $fallbackText);
        $best   = \trim($result ?? '');
        return $best !== '' ? $best : null;
    }

    /**
     * ----------------------------------------------
     * ZENTRALE HILFSMETHODE:
     * POST-Request an /v1/chat/completions mit
     * Retry-Mechanismus bei HTTP 429 (Rate Limit).
     *
     * @param array       $jsonPayload   Das JSON, das an OpenAI gesendet wird
     * @param string|null $fallback      Rückgabe falls alle Versuche scheitern
     *
     * @return string|null Im Erfolgsfall der ChatGPT-Text, sonst fallback
     */
    private function postChatCompletion(array $jsonPayload, ?string $fallback): ?string
    {
        // Versuche die Requests so oft, wie in $backoffWaitSeconds definiert (z. B. 3 Mal).
        $maxAttempts = \count($this->backoffWaitSeconds) + 1; // z. B. 3 Wartezeiten => 4 Versuche
        $attempt     = 0;

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
                // Wir holen uns den content-Feld aus der ersten Choice
                $content = $json['choices'][0]['message']['content'] ?? '';
                return \trim($content);

            } catch (ClientException $e) {
                // ClientException => 4xx/429 etc.
                $code    = $e->getResponse()?->getStatusCode() ?? 0;
                $message = $e->getMessage();

                if ($code === 429) {
                    // Rate Limit erreicht => Warten und erneut versuchen
                    if ($attempt < $maxAttempts) {
                        $waitSec = $this->backoffWaitSeconds[$attempt - 1] ?? 30;
                        $this->logger->warning(sprintf(
                            "OpenAI Rate-Limit (429) Versuch %d/%d. Warte %d Sek... (%s)",
                            $attempt,
                            $maxAttempts,
                            $waitSec,
                            $message
                        ));
                        \sleep($waitSec);
                        continue; // Nächster Versuch
                    }
                    // Letzter Versuch hat 429 geliefert => Abbrechen mit Fallback
                    $this->logger->error("OpenAI 429: Alle Versuche aufgebraucht. Nutze Fallback.");
                    return $fallback;
                } else {
                    // Andere 4xx-Fehler => Abbrechen
                    $this->logger->error("OpenAI error {$code}: {$message}");
                    return $fallback;
                }

            } catch (GuzzleException $e) {
                // Andere Guzzle-Fehler (z. B. Netzwerk), wir brechen ab
                $this->logger->error("OpenAI GuzzleException: " . $e->getMessage());
                return $fallback;
            } catch (\Throwable $t) {
                // Unbekannte Fehler, brechen wir ab
                $this->logger->error("OpenAI unknown error: " . $t->getMessage());
                return $fallback;
            }
        }

        // Wenn wir aus der Schleife rausfallen, ohne return => fallback
        return $fallback;
    }
}
