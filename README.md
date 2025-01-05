# **Shopware Affiliate Sync**

Projekt zur automatischen Synchronisation von Affiliate-Daten in eine Shopware-Instanz. Das Skript lädt z. B. Produktdaten aus einer oder mehreren CSV-Quelle(n), verarbeitet sie und legt die Produkte in Shopware an bzw. aktualisiert sie (Preis, Beschreibung, Lieferzeit etc.). Zusätzlich können optional Beschreibungen via ChatGPT (OpenAI) umformuliert werden.

---

## **Inhalt**

- [**Shopware Affiliate Sync**](#shopware-affiliate-sync)
  - [**Inhalt**](#inhalt)
  - [**Überblick**](#überblick)
  - [**Voraussetzungen**](#voraussetzungen)
  - [**Installation**](#installation)
  - [**Konfiguration \& .env**](#konfiguration--env)
  - [**Nutzung**](#nutzung)
  - [**Technische Hinweise**](#technische-hinweise)
    - [Performance-Tipps](#performance-tipps)
  - [**Fehlerbehebung / Troubleshooting**](#fehlerbehebung--troubleshooting)
  - [**Lizenz**](#lizenz)

---

## **Überblick**

- **Automatisiertes CSV-Parsing**: Mehrere CSV-Dateien gleichzeitig einlesen und verarbeiten.  
- **Shopware-Integration**: Erzeugt oder aktualisiert Produkte (Preise, Hersteller, Kategorien, Lieferzeiten usw.).  
- **Optionale KI-Unterstützung**: OpenAI kann Beschreibungen verfeinern und kategorienbezogene Zuordnungen vorschlagen.  
- **Flexible Konfiguration**: Konfigurierbar in einer `.env`-Datei (mehrere CSVs, Standard-Hersteller usw.).  

Dieses Projekt wurde von **JUNU** entwickelt, um verschiedene Affiliate-Datenfeeds effizient in Shopware 6 oder höher zu integrieren.

---

## **Voraussetzungen**

- **PHP 8.3+**
- **Composer**  
- **Shopware 6.4 oder höher** (getestet mit 6.4/6.5)  
- **Zugriff auf die Shopware Admin-API** (Client-ID & Client Secret)  
- (Optional) **OpenAI API-Key**, wenn ChatGPT-Funktionen benötigt werden  

---

## **Installation**

1. **Repository klonen**  
   ```bash
   git clone https://github.com/ju-nu/shopware-affiliate-sync.git
   ```
2. **In das Projektverzeichnis wechseln**  
   ```bash
   cd shopware-affiliate-sync
   ```
3. **Abhängigkeiten installieren**  
   ```bash
   composer install
   ```
4. **.env anlegen**  
   - Kopiere `.env.example` zu `.env`  
   - Passe die Werte an deine Umgebung an (API-URLs, Shopware-Credentials etc.)  

---

## **Konfiguration & .env**

In der `.env`-Datei werden z. B. folgende Werte festgelegt:

```bash
SHOPWARE_API_URL="https://deine-shopware-domain.tld"
SHOPWARE_CLIENT_ID="SHOPWARE_CLIENT_ID"
SHOPWARE_CLIENT_SECRET="SHOPWARE_CLIENT_SECRET"
SHOPWARE_SALES_CHANNEL_NAME="Storefront"

OPENAI_API_KEY="sk-xxxxx"
OPENAI_MODEL="gpt-4o-mini"

LOG_FILE="logs/app.log"

CSV_URL_1="https://example.com/affiliate-feed1.csv"
CSV_ID_1="CSV1"
CSV_MAPPING_1="ext_Vorschaubild-URL=Vorschaubild-URL|ext_Streichpreis=Streichpreis"
CSV_DEFAULT_MANUFACTURER_1="MeinStandardHersteller"

# Weitere CSVs hier definieren ...
```

- **CSV_URL_x**: URL der CSV-Datei  
- **CSV_ID_x**: Eine eindeutige Kennung, um Produkte zu unterscheiden  
- **CSV_MAPPING_x**: Optionales Spalten-Mapping, z. B. um `ext_...`-Spalten in Standard-Namen zu kopieren  
- **CSV_DEFAULT_MANUFACTURER_x**: Fallback-Herstellername, falls kein Hersteller in der CSV enthalten ist  

---

## **Nutzung**

- Starte den Sync-Befehl über Composer:
  ```bash
  composer start
  ```
  oder alternativ direkt:
  ```bash
  php bin/console sync
  ```

- Das Skript:
  1. Liest die `.env` ein und ermittelt alle konfigurierten CSV-Feeds.  
  2. Lädt jede CSV herunter, validiert und parst sie.  
  3. Legt ggf. neue Hersteller an oder aktualisiert vorhandene.  
  4. Erzeugt oder aktualisiert Shopware-Produkte (inkl. Preis, Streichpreis, custom fields, Bilder etc.).  
  5. Verwendet ChatGPT (optional) für Kategorievorschläge, Lieferzeit-Vorschläge und Überschreiben der Beschreibung.  

- **Logs** findest du unter `logs/app.log`.  

---

## **Technische Hinweise**

- **ShopwareService** übernimmt Authentifizierung, Produkt-CRUD, Medien-Uploads etc.  
- **OpenAiService** kümmert sich um Aufrufe an die ChatGPT-API, inkl. Umschreiben von Beschreibungen, Kategorievorschläge usw.  
- **CsvService** lädt und parst die CSV-Daten.  
- **CsvRowMapper** wandelt rohe CSV-Zeilen in einheitliche Strukturen (Preis, EAN/AAN, Hersteller etc.).  

### Performance-Tipps

- Bei großen CSV-Dateien kann eine Parallelisierung (z. B. via mehrere Prozesse/Worker) sinnvoll sein, um längere Verarbeitungszeiten zu reduzieren.  
- Überwache deinen Shopware-Server auf API-Request-Limits (zu viele gleichzeitige Bild-Uploads oder Produkt-Updates können zu Rate-Limits führen).  

---

## **Fehlerbehebung / Troubleshooting**

- **Authentifizierungsfehler**: Prüfe `SHOPWARE_CLIENT_ID` und `SHOPWARE_CLIENT_SECRET`.  
- **Leere CSV**: Vergewissere dich, dass `CSV_URL_x` korrekt erreichbar ist und die Datei nicht leer ist.  
- **OpenAI-Fehler**: Stelle sicher, dass dein `OPENAI_API_KEY` gültig ist und du keine Rate-Limits überschreitest.  
- **Dateirechte**: Achte darauf, dass PHP Schreibrechte auf `logs/` hat.  
- **Abstürze / Timeout**: Je nach Serverkonfiguration kann es bei sehr großen CSV-Daten zu Timeouts kommen. Erhöhe ggf. `max_execution_time` und speichere große CSVs lokal, bevor du sie verarbeitest.  

---

## **Lizenz**

Dieses Projekt steht unter keiner spezifischen Open-Source-Lizenz (Stand jetzt). Bitte beachte jedoch, dass die Nutzung von Shopware, OpenAI, PHP-Paketen etc. jeweils eigene Lizenzbestimmungen haben.  

Bei Fragen oder für kommerzielle Nutzung, kontaktiere uns gern direkt.

---

**Autor & Firma**  
- **Autor**: Sebastian Gräbner (sebastian@ju.nu)  
- **Firma**: [JUNU Marketing Group LTD](https://github.com/ju-nu)  

Viel Erfolg beim Einsatz des Tools!  