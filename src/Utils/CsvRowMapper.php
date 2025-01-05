<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Utility-Klasse zum Mapping einer CSV-Zeile auf die Felder eines Shopware-Produkts.
 */

namespace JUNU\RealADCELL\Utils;

final class CsvRowMapper
{
    /**
     * Mappt eine CSV-Zeile auf ein vereinheitlichtes Array.
     *
     * Regel für productNumber:
     *  - Falls AAN vorhanden => "CSV_ID-AAN"
     *  - Ansonsten, falls EAN vorhanden => "CSV_ID-EAN"
     *  - Ansonsten leer
     *
     * @param array  $row   Die CSV-Zeile
     * @param string $csvId Kennung der CSV
     */
    public static function mapRow(array $row, string $csvId): array
    {
        $deeplink        = $row['Deeplink']        ?? '';
        $title           = $row['Produkt-Titel']   ?? '';
        $description     = $row['Produktbeschreibung'] ?? '';
        $descriptionLang = $row['Produktbeschreibung lang'] ?? '';
        $bruttopreis     = $row['Preis (Brutto)']  ?? '';
        $streichpreis    = $row['Streichpreis']    ?? '';
        $ean             = $row['europäische Artikelnummer EAN'] ?? '';
        $aan             = $row['Anbieter Artikelnummer AAN']    ?? '';
        $manufacturer    = $row['Hersteller']      ?? '';
        $mainImage       = $row['Produktbild-URL'] ?? '';
        $fallbackImage   = $row['Vorschaubild-URL']?? '';
        $categoryHint    = $row['Produktkategorie']?? '';
        $shippingGeneral = $row['Versandkosten Allgemein'] ?? '';
        $lieferzeit      = $row['Lieferzeit']      ?? '';

        // Fallback auf "Produktbeschreibung lang"
        if (empty($description)) {
            $description = $descriptionLang;
        }

        // Wähle das beste Bild
        $imageUrl = !empty($mainImage) ? $mainImage : $fallbackImage;

        // productNumber zusammenbauen
        $productNumber = '';
        if (!empty($aan)) {
            $productNumber = $csvId . '-' . $aan;
        } elseif (!empty($ean)) {
            $productNumber = $csvId . '-' . $ean;
        }

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
