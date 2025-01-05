# JUNU RealADCELL – Aktuellste Version

Dieses Projekt dient dazu, **CSV-Dateien** (über ENV-Variablen konfiguriert) in **Shopware 6** zu **importieren** bzw. zu **aktualisieren**. Dabei werden:

- **Hersteller** (Manufacturer) automatisch angelegt oder wiederverwendet, inklusive einer **Fallback-Logik** über ENV.  
- **Kategorien** als **Baum** geladen und zu Pfaden wie „Elektronik > Tablets > iPad“ zusammengefasst. Mit **OpenAI** (ChatGPT) wird dann die passendste untergeordnete Kategorie gewählt.  
- **Preise** unterschiedlich gesetzt (Streichpreis vs. Bruttopreis), sodass:
  - Falls **kein** Streichpreis vorhanden: `price = Bruttopreis`, kein `listPrice`.  
  - Falls **Streichpreis** vorhanden: `price = Streichpreis`, `listPrice = Bruttopreis`.  
- **Cover-Bild** in nur **einem** Aufruf gesetzt (kein zweiter PATCH-Call nötig), durch:
- Ein **Re-Write** der Produktbeschreibung via **OpenAI** auf Deutsch, ohne Wiederholen des Titels, mit dem Endpunkt `/v1/chat/completions`.

## Features

1. **Mehrere CSVs** konfigurierbar in `.env`:
   - `CSV_URL_1`, `CSV_ID_1`, `CSV_MAPPING_1`, etc.  
2. **CSV Parsing** (semikolon-getrennt, ggf. Mappings) mit **Pflicht-Spalten** wie `Produkt-Deeplink`, `Produkt-Titel`, `Bruttopreis`, `Streichpreis`, etc.  
3. **Shopware-Authentifizierung** per `client_credentials`, Abruf einer **Standard-Steuer** (Tax) per `position=1`, und **Sales Channel** per Name (ENV: `SHOPWARE_SALES_CHANNEL_NAME`).  
4. **Manufacturer**:
   - Gesucht per **POST** auf `/api/search/product-manufacturer`  
   - Falls nicht gefunden => neuer Eintrag via POST `/api/product-manufacturer`  
   - Vermeidet Duplikate durch einen lokalen statischen Cache.  
   - **Fallback** (z. B. `CSV_DEFAULT_MANUFACTURER_1`) falls CSV keinen Hersteller liefert.  
5. **Cover** in einem **einzigen** Produkterstellungs-Aufruf. Kein zweiter PATCH nötig.  
6. **Preis-Logik**:
   - **Ohne Streichpreis**: `price = Bruttopreis`; kein `listPrice`.
   - **Mit Streichpreis**: `price = Streichpreis`; `listPrice = Bruttopreis`.  
7. **OpenAI**:
   - `rewriteDescription($title, $description)` => Befehl an ChatGPT, um deutsche Beschreibungen zu generieren.

## Voraussetzungen

- **PHP** (7.4 / 8.0+)  
- **Composer**  
- **Shopware 6**-Installation (Admin-API-Zugang)  
- **Guzzle** für HTTP-Requests  
- **PSR-Log** (z. B. Monolog)  
- **ENV**-Datei mit allen benötigten Variablen

## Installation

1. **Repository klonen** oder Code herunterladen.  
2. `composer install` ausführen (sofern composer.json vorhanden).  
3. `.env.example` zu `.env` kopieren und dort alle Werte anpassen, z. B.:
   - `SHOPWARE_API_URL="https://deine-shopware-url.de"`
   - `SHOPWARE_CLIENT_ID="abcdefgh"`
   - `SHOPWARE_CLIENT_SECRET="super-secret"`
   - `SHOPWARE_SALES_CHANNEL_NAME="Storefront"`
   - `CSV_URL_1="https://example.com/my.csv"`
   - `CSV_ID_1="CSV1"`
   - `CSV_MAPPING_1="Deeplink=Produkt-Deeplink|ext_Streichpreis=Streichpreis|usw..."`
   - `CSV_DEFAULT_MANUFACTURER_1="MyDefaultHersteller"`

## Aufbau der Dateien

- **`SyncCommand.php`**  
  Enthält die **Hauptlogik** zum Einlesen der CSV-Zeilen, der Kategoriefindung via ChatGPT, dem Uploader der Bilder, und der Preissetzung.  
- **`ShopwareService.php`**  
  Kapselt alle **Shopware-spezifischen** API-Aufrufe:  
  - Authentifizierung (`/api/oauth/token`)  
  - Hersteller-Suche via POST `/api/search/product-manufacturer`  
  - Hersteller-Anlage via POST `/api/product-manufacturer`  
  - Produkt-Erstellung (`createProduct`)  
  - Produkt-Update (`updateProduct`) – hier ggf. listPrice, customFields etc.  
  - Bild-Upload in zwei Schritten (Media erstellen, dann Upload via `_action/media/{id}/upload`).  
  - Kategorie-Tree laden und flatten.  
- **`CsvService.php`**  
  Liest und **parsed** CSV, setzt Mappings um (z. B. `"Deeplink"= "Produkt-Deeplink"`), stellt sicher, dass bestimmte Pflichtspalten existieren (sonst leer).  
- **`OpenAiService.php`** (optional)  
  Macht die **Rewrite**-Aufrufe an ChatGPT (bzw. OpenAI API).  
- **`CsvRowMapper.php`**  
  Wandelt CSV-Zeilen in ein internes Datenarray um (z. B. `$mapped['priceBrutto'] = ...`).

## Workflow

1. **CSV-Parsing**  
   - Pro CSV wird `fetchAndParseCsv($url, $mapping)` aufgerufen, um Zeilen als Arrays zu erhalten.  
   - Pflichtspalten wie `Produktbeschreibung`, `Streichpreis`, etc. werden garantiert, ggf. leer.  
2. **Pro Zeile**:
   - **EAN + AAN** prüfen. Sind beide leer => `skip`.  
   - **Manufacturer** suchen/erstellen (`findOrCreateManufacturerForCsv`):
     - Wenn CSV leer => `CSV_DEFAULT_MANUFACTURER_{index}`.  
     - Sonst Post-Search => Post-Create, Cache.  
   - **Kategorie** => ChatGPT (bestCategory). Ermittelt Pfad wie „Elektronik > Smartphones > iPhones“.  
   - **Lieferzeit** => ChatGPT (bestDeliveryTime).  
   - **Preis** => Falls `Streichpreis` leer => `price=Brutto`. Falls `Streichpreis` gefüllt => `price=Streich`, `listPrice=Brutto`.  
   - **Bilder** => `uploadImages([...])` => array von mediaIds. Dann:
     ```php
     $payload['cover'] = ['mediaId' => $mediaIds[0]];
     $payload['media'] = array_map(fn($id) => ['mediaId' => $id], $mediaIds);
     ```
   - **Beschreibung** => `rewriteDescription($title, $description)` => in Deutsch, ohne Titelwiederholung.  
   - **createProduct** (noch kein Patch nötig).  
3. **Kein** doppelter Call für coverId.  
4. **Erfolg**- oder **Fehlermeldung** wird geloggt.

## .env Beispiel

```bash
SHOPWARE_API_URL="https://my-shopware.com"
SHOPWARE_CLIENT_ID="xyz123"
SHOPWARE_CLIENT_SECRET="secret-abc"

SHOPWARE_SALES_CHANNEL_NAME="Storefront"

OPENAI_API_KEY="sk-..."
OPENAI_MODEL="gpt-4o-mini"

# CSV 1
CSV_URL_1="https://example.com/file1.csv"
CSV_ID_1="CSV1"
CSV_MAPPING_1="Streich=X|StreichC=Y"

CSV_DEFAULT_MANUFACTURER_1="HerstellerFuerCSV1"
```

## Ausführung

- **Kommandozeile**:  
  ```bash
  php bin/console sync
  ```
  (Annahme: du hast eine Symfony-Console-Struktur oder ähnliches).  
- Das Skript liest `.env`, authentifiziert sich bei Shopware, lädt Kategorien, CSV-Zeilen etc., und erstellt bzw. aktualisiert die Produkte.

## Wichtige Hinweise

- **Keine doppelten Hersteller** mehr, sofern der Name im CSV **exakt** übereinstimmt. Minimale Abweichungen (Groß-/Kleinschreibung, Leerzeichen) erzeugen neue Einträge.  
- **Cover**-Bild wird direkt beim Erstellen gesetzt, kein zweiter PATCH.  
- **ListPrice**/**Price**-Logik invertiert bei vorhandenem Streichpreis.  
- **ChatGPT**-Aufrufe ggf. an Tokenlimit und Rate-Limits denken.  
- **Kategorie**: ChatGPT kann nur aus den existierenden Flatten-Keys „Parent > Child“ wählen. Prüfe, ob du duplizierte Pfade in Shopware hast.  

## Fazit

Mit dieser Version erhältst du ein **CSV-zu-Shopware-Importsystem**, das:

1. Vollständig in **einem** Schritt das **Cover** setzt,  
2. **Keine** Mehrfach-Hersteller anlegt, solange der Name identisch ist,  
3. **Streichpreis**-Logik anpasst (Streich = price, Brutto = listPrice)  
4. **Beschreibung** auf Deutsch rescribet.

Viel Erfolg beim Import!  
