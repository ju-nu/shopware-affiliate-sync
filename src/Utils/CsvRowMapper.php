<?php

namespace JUNU\RealADCELL\Utils;

/**
 * Class CsvRowMapper
 * Maps CSV row data to a more structured product data array.
 */
class CsvRowMapper
{
    /**
     * Map a row from the CSV into a standardized array
     * applying fallback logic (e.g. if "Produktbeschreibung" is empty, use "Produktbeschreibung lang").
     */
    public static function mapRow(array $row, string $csvId): array
    {
        $deeplink        = $row['Produkt-Deeplink']        ?? '';
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
        $lieferzeit      = $row['Lieferzeit']             ?? '';

        if (empty($description)) {
            $description = $descriptionLang;
        }

        // Choose final image
        $imageUrl = $mainImage ?: $fallbackImage;

        // Construct productNumber (EAN if present, else csvId + AAN)
        $productNumber = $ean ? $ean : ($aan ? $csvId . $aan : '');

        return [
            'deeplink'         => $deeplink,
            'title'            => $title,
            'description'      => $description,
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
