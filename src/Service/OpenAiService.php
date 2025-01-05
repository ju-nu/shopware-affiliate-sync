<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Service-Klasse zur Kommunikation mit OpenAI (ChatGPT).
 */

namespace JUNU\ShopwareAffiliateSync\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class OpenAiService
{
    private Client $client;
    private LoggerInterface $logger;
    private string $openAiKey;
    private string $model;

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
     * Umschreiben der Produktbeschreibung auf Deutsch, ohne den Titel zu wiederholen.
     */
    public function rewriteDescription(string $title, string $description): string
    {
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

        try {
            $response = $this->client->post('/v1/chat/completions', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$this->openAiKey}",
                ],
                'json' => [
                    'model'    => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 400,
                ],
            ]);

            $json       = \json_decode($response->getBody()->getContents(), true);
            $rewritten  = $json['choices'][0]['message']['content'] ?? '';
            return \trim($rewritten);
        } catch (\Throwable $e) {
            $this->logger->error("OpenAI rewriteDescription error: {$e->getMessage()}");
            return $description; // Fallback
        }
    }

    /**
     * Ermittelt die passendste Kategorie aus Shopware basierend auf dem Titel, der Beschreibung
     * und einem CSV-Kategorie-Hinweis.
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

        try {
            $response = $this->client->post('/v1/chat/completions', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$this->openAiKey}",
                ],
                'json' => [
                    'model'    => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 50,
                ],
            ]);

            $json = \json_decode($response->getBody()->getContents(), true);
            $best = \trim($json['choices'][0]['message']['content'] ?? '');
            return $best !== '' ? $best : null;
        } catch (\Throwable $e) {
            $this->logger->error("OpenAI bestCategory error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Ermittelt die passende Lieferzeit aus Shopware basierend auf einer CSV-Lieferzeitangabe.
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

        try {
            $response = $this->client->post('/v1/chat/completions', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$this->openAiKey}",
                ],
                'json' => [
                    'model'    => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 50,
                ],
            ]);

            $json = \json_decode($response->getBody()->getContents(), true);
            $best = \trim($json['choices'][0]['message']['content'] ?? '');
            return $best !== '' ? $best : null;
        } catch (\Throwable $e) {
            $this->logger->error("OpenAI bestDeliveryTime error: {$e->getMessage()}");
            return null;
        }
    }
}
