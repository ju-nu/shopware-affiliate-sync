# JUNU RealADCELL Sync

Autor: Sebastian Gräbner (<sebastian@ju.nu>)  
Firma: JUNU Marketing Group LTD

## Überblick

Dieses Projekt dient dem automatischen Import und der Synchronisation von Produktdaten aus mehreren CSV-Dateien in ein Shopware-6.6-System. Dabei werden ergänzend OpenAI-Dienste genutzt, um Texte (z. B. Produktbeschreibungen) automatisiert zu optimieren.  

**Hauptfunktionen:**
- CSV-Import (mehrere Dateien konfigurierbar über ENV)
- Erstellung/Aktualisierung von Produkten in Shopware
- Teilautomatisierte Kategorisierung und Lieferzeit via ChatGPT
- Neuschreiben der Produktbeschreibung via ChatGPT (nur bei neuen Produkten)

## Voraussetzungen

- PHP >= 8.3
- Composer
- Shopware 6.6 (oder kompatible API-Endpoints)
- Gültiger OpenAI-API-Key

## Installation

1. **Repository klonen**  
   ```bash
   git clone https://github.com/username/realadcell-sync.git
   cd realadcell-sync
   ```

2. **Abhängigkeiten installieren**  
   ```bash
   composer install
   ```

3. **.env anlegen**  
   Kopiere die Datei `.env.example` zu `.env` und passe die Werte an (Shopware-API-Zugang, OpenAI-Key, CSV-URLs etc.).

4. **Log-Verzeichnis anlegen** (falls nicht vorhanden)  
   ```bash
   mkdir -p logs
   ```

## Verwendung

- **CSV-Definitionen in .env**  
  Du kannst mehrere CSV-Dateien definieren, z. B.:
  ```text
  CSV_URL_1="https://example.com/file1.csv"
  CSV_ID_1="CSV1"
  CSV_MAPPING_1="ext_Vorschaubild-URL=Vorschaubild-URL|ext_Streichpreis=Streichpreis"

  CSV_URL_2="https://example.com/file2.csv"
  CSV_ID_2="CSV2"
  CSV_MAPPING_2="..."
  ```
  Jeder Eintrag `CSV_URL_x` wird automatisch erkannt und verarbeitet.

- **Sync starten**  
  ```bash
  composer start
  ```
  oder
  ```bash
  php bin/console sync
  ```

- **Logs**  
  Standardmäßig werden Logs unter `logs/app.log` geschrieben (kann in `.env` über `LOG_FILE` geändert werden). Zusätzlich wird auch auf der Konsole geloggt.

## Wichtige Umgebungsvariablen

| Variable                         | Beschreibung                                                     |
|----------------------------------|-----------------------------------------------------------------|
| `SHOPWARE_API_URL`              | Basis-URL der Shopware-API, z. B. `https://dein-shopware.tld`   |
| `SHOPWARE_CLIENT_ID`            | Client-ID für OAuth2-Authentifizierung in Shopware              |
| `SHOPWARE_CLIENT_SECRET`        | Client-Secret für OAuth2                                        |
| `SHOPWARE_SALES_CHANNEL_NAME`   | Name des Sales Channels (z. B. `Storefront`)                    |
| `OPENAI_API_KEY`                | API-Key für OpenAI                                              |
| `OPENAI_MODEL`                  | Modellname (z. B. `gpt-4o-mini`)                                |
| `CSV_URL_1`, `CSV_ID_1`, ...    | CSV-Definitionen (URL, ID, Mapping)                             |
| `SHOPWARE_CUSTOMFIELD_DEEPLINK` | Key für das DeepLink-CustomField                                |
| `SHOPWARE_CUSTOMFIELD_SHIPPING_GENERAL` | Key für das Versandinfo-CustomField                     |

## Anpassungen

- **Preisberechnung**: Derzeit wird die Netto-Berechnung als `gross / 1.19` umgesetzt. Das solltest Du ggf. an Deine tatsächlichen MwSt.-Sätze anpassen.
- **Mapping**: Mit `CSV_MAPPING_x` kannst Du CSV-Spalten „umkopieren“.  
- **OpenAI**: Standardmäßig werden nur neue Produkte über OpenAI verarbeitet. Bestehende Produkte werden lediglich aktualisiert (keine ChatGPT-Kosten).

## Lizenz

Dieses Projekt ist proprietär oder unterliegt einer Lizenz nach Wahl von JUNU Marketing Group LTD. Bitte setze Dich für Details mit uns in Verbindung.

---

Viel Erfolg beim Einsetzen des Scripts!