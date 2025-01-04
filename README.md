# JUNU RealADCELL Sync

This is an example **PHP CLI** application (namespaced under `JUNU\RealADCELL`) that:

- Fetches multiple CSV files (defined in `.env`).
- Parses and processes them (rewriting product descriptions with OpenAI).
- Creates or updates products in **Shopware 6.6** via the Admin API.
- Uploads images, creates manufacturers if needed.
- Uses **Monolog** for advanced logging and **Symfony Console** for a proper CLI.
- Uses **ramsey/uuid** to generate 32-character hex IDs without dashes.

## Project Structure

- `bin/console`  
  The entry point for CLI commands (Symfony Console).
- `src/Commands/SyncCommand.php`  
  Orchestrates CSV reading, OpenAI calls, and Shopware updates.
- `src/Service/CsvService.php`  
  Fetches and parses the CSV files.
- `src/Service/OpenAiService.php`  
  Handles rewriting product descriptions, category/delivery time matching.
- `src/Service/ShopwareService.php`  
  Interacts with the Shopware Admin API (authentication, product creation/updating, etc.).
- `src/Service/UuidService.php`  
  Creates 32-character hex UUIDs from ramsey/uuid.
- `src/Utils/CsvRowMapper.php`  
  Maps CSV rows to a structured product data array.
- `src/EnvLoader.php`  
  Loads `.env` environment variables (via `vlucas/phpdotenv`).
- `src/LoggerFactory.php`  
  Creates a [Monolog](https://github.com/Seldaek/monolog) logger instance with both console + file outputs.

## Usage

1. **Clone** the repo.
2. Copy `.env.example` to `.env` and fill in values (`SHOPWARE_API_URL`, credentials, `OPENAI_API_KEY`, etc.).
3. Run:
   `composer install`
5. Execute:
`php bin/console sync`
or make bin/console executable and run `./bin/console sync`.

## Logging & Error Handling

- Logs are written to the console (stdout) and the file specified in .env (LOG_FILE).
- If CSV is malformed or a row is missing critical info (EAN/AAN), we skip it and log a warning/error.
- The script uses try/catch to handle exceptions from both OpenAI and Shopware.

## Further Customization
- Adjust concurrency or parallelization if needed.
- Add caching for repeated OpenAI calls if you want to reduce usage.
- Modify how categories or delivery times are matched if your Shopware instance is more complex.
Enjoy!