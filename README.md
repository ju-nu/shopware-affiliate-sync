# Shopware 6.6 CSV Sync Script

A PHP CLI script to **fetch multiple CSV files**, parse and transform the data, and **create or update products** in Shopware 6.6. It also uses **OpenAI** to:
- Rewrite product descriptions based on title and description.
- Suggest the best Shopware category (most child) based on the product's CSV category, title, and description.
- Match delivery times from a set of existing Shopware delivery time entities.

## Features

- **Environment-based configuration** (`.env`) for all credentials and CSV definitions
- **Logs** to file and console with timestamps
- **Rate limiting** / sequential approach to avoid overloading OpenAI
- **Skips** products with missing identifiers (EAN/AAN)
- **Creates** or **updates** manufacturers if not found
- **Uploads** product images to Shopware (or re-uses existing media by filename)

## Setup

1. **Clone** this repository.
2. Copy `.env.example` to `.env` and fill in all required variables.
3. (Optional) Run `composer install` if you're using external libraries like `guzzlehttp/guzzle`, `symfony/dotenv`, etc.
4. **Run** the script:
   ```bash
   php script.php
