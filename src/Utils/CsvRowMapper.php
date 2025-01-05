<?php

namespace JUNU\RealADCELL\Utils;

/**
 * Class CsvRowMapper
 * Updated logic so productNumber is always "CSV_ID + AAN" if AAN is present;
 * otherwise "CSV_ID + EAN" if EAN is present.
 * We remove the product title from the 'description' field; only keep CSV description.
 */
class CsvRowMapper
{
    public static function mapRow(array $row, string $csvId): array
    {
        $deeplink        = $row['Deeplink']        ?? '';
        $title           = $row['Produkt-Titel']           ?? '';
        $description     = $row['Produktbeschreibung']     ?? '';
        $descriptionLang = $row['Produktbeschreibung lang'] ?? '';
        $bruttopreis     = $row['Preis (Brutto)']          ?? '';
        $streichpreis    = $row['Streichpreis']            ?? '';
        $ean             = $row['europäische Artikelnummer EAN'] ?? '';
        $aan             = $row['Anbieter Artikelnummer AAN']    ?? '';
        $manufacturer    = $row['Hersteller']              ?? '';
        $mainImage       = $row['Produktbild-URL']         ?? '';
        $fallbackImage   = $row['Vorschaubild-URL']        ?? '';
        $categoryHint    = $row['Produktkategorie']        ?? '';
        $shippingGeneral = $row['Versandkosten Allgemein'] ?? '';
        $lieferzeit      = $row['Lieferzeit']              ?? '';

        // If "Produktbeschreibung" empty, fallback to "lang"
        if (empty($description)) {
            $description = $descriptionLang;
        }

        // Use mainImage if available, else fallback
        $imageUrl = $mainImage ?: $fallbackImage;

        // PRODUCT NUMBER RULE:
        //  - If AAN present => CSV_ID + AAN
        //  - else if EAN present => CSV_ID + EAN
        //  - else empty
        $productNumber = '';
        if (!empty($aan)) {
            $productNumber = $csvId . '-' . $aan;
        } elseif (!empty($ean)) {
            $productNumber = $csvId . '-' . $ean;
        }

        return [
            'deeplink'         => $deeplink,
            'title'            => $title,         // We'll keep the title if needed
            'description'      => $description,   // We'll rewrite it in German via OpenAI (no title included!)
            'priceBrutto'      => $bruttopreis,
            'listPrice'        => $streichpreis,
            'ean'              => $ean,
            'aan'              => $aan,
            'manufacturer'     => $manufacturer,
            'imageUrl'         => $imageUrl,
            'categoryHint'     => $categoryHint,
            'shippingGeneral'  => $shippingGeneral,
            'deliveryTimeCsv'  => $lieferzeit,
            'productNumber'    => $productNumber,
        ];
    }
}
