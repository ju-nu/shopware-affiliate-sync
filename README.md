# JUNU RealADCELL Sync

Dieses Projekt demonstriert eine **PHP CLI**-Anwendung (im Namespace `JUNU\RealADCELL`), die:

- Mehrere CSV-Dateien (konfiguriert in einer `.env`) herunterlädt und ausliest.  
- Die enthaltenen Produkte über die **Shopware 6.6 Admin-API** erstellt oder aktualisiert.  
- **OpenAI** verwendet, um Produktbeschreibungen umzuschreiben und Kategorien/Lieferzeiten zuzuordnen.  
- Produktbilder in Shopware hochlädt und Hersteller bei Bedarf anlegt.  
- **Monolog** als Logging-Bibliothek einsetzt.  
- **ramsey/uuid** nutzt, um 32-stellige Hex-IDs zu erzeugen.  
- **Symfony Console** (Kommandozeilen-Komponente) für einen professionellen CLI-Workflow einbindet.

---

## Projektaufbau

```
real-adcell-sync/
├── bin/
│   └── console               # Entry-Point für CLI-Kommandos
├── composer.json
├── .gitignore
├── .env.example              # Beispiel-Konfiguration
├── README.md
├── src/
│   ├── EnvLoader.php         # Lädt Umgebungsvariablen (.env)
│   ├── LoggerFactory.php     # Erstellt einen Monolog-Logger
│   ├── Commands/
│   │   └── SyncCommand.php   # Hauptkommando für CSV-Verarbeitung
│   ├── Service/
│   │   ├── CsvService.php    # Lädt und parst CSV-Dateien
│   │   ├── OpenAiService.php # Spricht OpenAI an (Rewrite, Category Match)
│   │   ├── ShopwareService.php
│   │   └── UuidService.php   # Generiert 32stellige UUID-Hex-Werte
│   └── Utils/
│       └── CsvRowMapper.php  # Mapped CSV-Zeilen auf Produkt-Struktur
└── logs/
    └── app.log               # Standard-Log-Datei (nicht im Repo)
```

---

## Installation & Nutzung

1. **Repository klonen** oder Code herunterladen.
2. `.env.example` nach `.env` kopieren und dort alle Werte anpassen:
   - **SHOPWARE_API_URL**, **SHOPWARE_CLIENT_ID**, **SHOPWARE_CLIENT_SECRET**
   - **OPENAI_API_KEY**, **OPENAI_MODEL** (z.B. `gpt-4o-mini`)
   - **LOG_FILE** (Standard: `logs/app.log`)
   - CSV-Definitionen wie `CSV_URL_1`, `CSV_ID_1`, `CSV_MAPPING_1` usw.
3. **Dependencies installieren**:
   ```bash
   composer install
   ```
4. **Cronjob oder manuell** ausführen:
   ```bash
   php bin/console sync
   ```
   oder ausführbar machen:
   ```bash
   chmod +x bin/console
   ./bin/console sync
   ```

---

## Funktionsweise

- **.env**: Enthält URLs zu den CSVs und deren IDs/Mappings, Shopware-Zugangsdaten sowie OpenAI-Token.  
- **CsvService**: Lädt per `file_get_contents` den CSV-Inhalt, parst ihn (Semikolon-getrennt) und wendet Mappings an (z.B. `ext_Vorschaubild-URL=Vorschaubild-URL`).  
- **CsvRowMapper**: Liest die wichtigsten Felder (EAN, AAN, Preis, etc.) aus und vereinheitlicht sie in ein Array.  
- **SyncCommand**:
  1. Holt sich Kategorien und Lieferzeiten von Shopware.  
  2. Für jede CSV-Zeile prüft es, ob ein Produkt existiert (per EAN oder `CSV_ID + AAN`).  
  3. Falls nicht vorhanden, wird ein neues Produkt erstellt (mit **OpenAI**-Rewrite der Beschreibung, Kategorie-Match, Bild-Upload etc.).  
  4. Andernfalls werden nur gewisse Felder (Preis, Deeplink, Lieferzeit) aktualisiert.  
- **OpenAiService**: Sendet Title/Description an den Chat-Completions-Endpunkt (Modell `gpt-4o-mini`) und bekommt eine neue, umgeschriebene Beschreibung zurück. Auch Kategorie- und Lieferzeit-Matching erfolgen über das LLM.  
- **ShopwareService**:  
  - Authentifiziert per `client_credentials` (speichert Token).  
  - Ruft `product-manufacturer`, `category`, `delivery-time` sowie `product`-Endpunkte auf, um Produkte zu finden bzw. zu erstellen/aktualisieren.  
  - Upload von Bildern: Legt zunächst ein `media`-Objekt in Shopware an, dann `uploadImageFromUrl` mit der externen Bild-URL.  
- **UuidService** (ramsey/uuid): Erzeugt eine UUID v4, entfernt jedoch die Bindestriche (`-`), damit eine 32-stellige Hex-Zeichenkette entsteht (`^[0-9a-f]{32}$`).  
- **LoggerFactory**:  
  - Erstellt einen Monolog-Logger.  
  - Leitet Log-Einträge sowohl in eine Datei (Standard: `logs/app.log`) als auch in die Konsole (`php://stdout`).  

---

## Beispiel-Workflow

1. Der **Cronjob** läuft einmal täglich:
   - Ruft `php bin/console sync` auf.  
2. Das Script:
   - Lädt `CSV_URL_1`, parst alle Zeilen.  
   - Für jede Zeile:  
     - EAN + AAN prüfen  
     - OpenAI-Aufrufe zum Umschreiben der Beschreibung  
     - Shopware-Calls zum Erstellen/Aktualisieren des Produkts  
   - Wiederholt den Vorgang für weitere CSVs (z.B. `CSV_URL_2`)  
3. **Logs** landen in der Konsole & in `logs/app.log`, inkl. Zeitstempel und Warnungen/Fehler.

---

## Hinweise & Anpassungen

- **Erweiterungen**:
  - Caching von OpenAI-Ergebnissen (z.B. Redis), um Tokens zu sparen.  
  - Parallele Verarbeitung (z.B. mit [Spatie/async](https://github.com/spatie/async)).  
  - Komplexere Kriterien für Kategorien (z.B. Pfad/Hierarchie in Shopware).  
- **Fehlerbehandlung**:
  - Bei API-Fehlern von Shopware/OpenAI wird per `log->error()` protokolliert und das betroffene Produkt geskippt.  
- **Shopware-Einstellungen**:
  - Achte darauf, dass die Währung (Euro) und die `currencyId` korrekt konfiguriert sind. Hier nutzen wir default: `b7d2554b0ce847cd82f3ac9bd1c0dfca`.  
- **PHP Version**: Das Beispiel setzt mind. PHP 7.4 oder 8.x voraus.  

