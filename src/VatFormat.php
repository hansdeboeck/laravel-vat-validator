<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator;

/**
 * Controle van het formaat van een btw-nummer, zonder een enkele bron te
 * bevragen.
 *
 * WAARVOOR DIT DIENT. Een formulier dat bij elke toets VIES bevraagt, legt
 * VIES plat en wacht zelf seconden op een antwoord. Wat hier staat, antwoordt
 * meteen: klopt de vorm voor dat land, en voor de landen waar dat kan, klopt
 * het controlegetal. Pas wanneer dat in orde is, heeft een echte lookup zin.
 *
 * WAT DIT NIET ZEGT, is of het nummer bestaat. Een nummer met een juist
 * controlegetal kan nooit uitgereikt of al lang ingetrokken zijn.
 *
 * De patronen volgen die van VIES zelf. Een controlegetal wordt nagerekend
 * voor BE en NL; voor de andere landen staat `checksum` op null: niet
 * nagekeken, en dus ook niet fout.
 */
final class VatFormat
{
    /**
     * Wat er na de landcode mag staan, per lidstaat. Griekenland is EL en
     * niet GR, Noord-Ierland XI: zo schrijft VIES ze.
     */
    private const PATTERNS = [
        'AT' => 'U\d{8}',
        'BE' => '[01]\d{9}',
        'BG' => '\d{9,10}',
        'CY' => '\d{8}[A-Z]',
        'CZ' => '\d{8,10}',
        'DE' => '\d{9}',
        'DK' => '\d{8}',
        'EE' => '\d{9}',
        'EL' => '\d{9}',
        'ES' => '[A-Z0-9]\d{7}[A-Z0-9]',
        'FI' => '\d{8}',
        'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}',
        'HR' => '\d{11}',
        'HU' => '\d{8}',
        'IE' => '\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W]',
        'IT' => '\d{11}',
        'LT' => '\d{9}|\d{12}',
        'LU' => '\d{8}',
        'LV' => '\d{11}',
        'MT' => '\d{8}',
        'NL' => '\d{9}B\d{2}',
        'PL' => '\d{10}',
        'PT' => '\d{9}',
        'RO' => '\d{2,10}',
        'SE' => '\d{12}',
        'SI' => '\d{8}',
        'SK' => '\d{10}',
        'XI' => '\d{9}|\d{12}|GD\d{3}|HA\d{3}',
    ];

    /**
     * Spaties, punten en streepjes eruit, in hoofdletters. Een kaal nummer van
     * negen of tien cijfers is Belgisch: zo typen Belgen hun ondernemingsnummer.
     */
    public static function normalize(string $vat): string
    {
        $vat = strtoupper((string) preg_replace('/[\s.\-]/', '', $vat));

        if (preg_match('/^\d{9,10}$/', $vat)) {
            $vat = 'BE' . str_pad($vat, 10, '0', STR_PAD_LEFT);
        }

        return $vat;
    }

    /**
     * @return array{vatNumber: string, countryCode: ?string, validFormat: bool, checksum: ?bool, error: ?string}
     */
    public static function check(string $vat): array
    {
        $vat = self::normalize($vat);
        $country = strlen($vat) >= 2 ? substr($vat, 0, 2) : null;
        $number = (string) substr($vat, 2);

        $out = fn (bool $format, ?bool $checksum, ?string $error) => [
            'vatNumber' => $vat,
            'countryCode' => $country,
            'validFormat' => $format && $checksum !== false,
            'checksum' => $checksum,
            'error' => $error,
        ];

        if ($country === 'GR') {
            return $out(false, null, 'Use EL as the country code for Greece');
        }

        if ($country === null || ! isset(self::PATTERNS[$country])) {
            return $out(false, null, 'Unknown country code');
        }

        if (! preg_match('/^(?:' . self::PATTERNS[$country] . ')$/', $number)) {
            return $out(false, null, 'Invalid format for ' . $country);
        }

        $checksum = match ($country) {
            'BE' => self::belgium($number),
            'NL' => self::netherlands($number),
            default => null,
        };

        return $out(true, $checksum, $checksum === false ? 'Invalid check digits' : null);
    }

    /**
     * De laatste twee cijfers zijn 97 min de rest van de eerste acht bij
     * deling door 97. Dat geldt ook voor de nummers die met een 1 beginnen.
     */
    private static function belgium(string $number): bool
    {
        return 97 - ((int) substr($number, 0, 8) % 97) === (int) substr($number, 8, 2);
    }

    /**
     * Twee regels, en een van de twee volstaat. Het oude omzetbelastingnummer
     * draagt de elfproef op de eerste negen cijfers; het btw-id dat eenmanszaken
     * sinds 2020 krijgen, voldoet aan mod 97 over "NL" plus het nummer, met de
     * letters als getallen (A=10 ... Z=35).
     */
    private static function netherlands(string $number): bool
    {
        $digits = substr($number, 0, 9);
        $sum = 0;

        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $digits[$i] * (9 - $i);
        }

        if ($sum % 11 === (int) $digits[8]) {
            return true;
        }

        $numeric = '';
        foreach (str_split('NL' . $number) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // In stukken, want het getal past niet in een integer.
        $rest = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $rest = (int) ($rest . $chunk) % 97;
        }

        return $rest === 1;
    }
}
