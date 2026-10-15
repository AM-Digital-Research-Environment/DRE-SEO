<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * BCP 47 language tags for the archive's language records.
 *
 * AMIRA's language authority items (template 6, dcterms:type "Language") are
 * titled in English ("German") and keep their ISO 639-2/3 code in
 * dcterms:alternative ("deu"). schema.org `inLanguage`, `citation_language`
 * and `DC.language` want a BCP 47 tag, which uses the two-letter ISO 639-1
 * code where one exists ("de") and the three-letter code otherwise ("pcm"
 * for Nigerian Pidgin). Anything that is not a language code at all is not a
 * tag, and yields none.
 *
 * The table is every ISO 639-2 code (bibliographic and terminology forms)
 * that has an ISO 639-1 equivalent, from the Library of Congress's
 * ISO-639-2_utf-8.txt.
 */
final class LanguageCode
{
    /** @var array<string,string> ISO 639-2/3 code => ISO 639-1 code */
    private const TWO_LETTER = [
        'aar' => 'aa', 'abk' => 'ab', 'afr' => 'af', 'aka' => 'ak', 'alb' => 'sq', 'amh' => 'am', 'ara' => 'ar', 'arg' => 'an',
        'arm' => 'hy', 'asm' => 'as', 'ava' => 'av', 'ave' => 'ae', 'aym' => 'ay', 'aze' => 'az', 'bak' => 'ba', 'bam' => 'bm',
        'baq' => 'eu', 'bel' => 'be', 'ben' => 'bn', 'bis' => 'bi', 'bod' => 'bo', 'bos' => 'bs', 'bre' => 'br', 'bul' => 'bg',
        'bur' => 'my', 'cat' => 'ca', 'ces' => 'cs', 'cha' => 'ch', 'che' => 'ce', 'chi' => 'zh', 'chu' => 'cu', 'chv' => 'cv',
        'cor' => 'kw', 'cos' => 'co', 'cre' => 'cr', 'cym' => 'cy', 'cze' => 'cs', 'dan' => 'da', 'deu' => 'de', 'div' => 'dv',
        'dut' => 'nl', 'dzo' => 'dz', 'ell' => 'el', 'eng' => 'en', 'epo' => 'eo', 'est' => 'et', 'eus' => 'eu', 'ewe' => 'ee',
        'fao' => 'fo', 'fas' => 'fa', 'fij' => 'fj', 'fin' => 'fi', 'fra' => 'fr', 'fre' => 'fr', 'fry' => 'fy', 'ful' => 'ff',
        'geo' => 'ka', 'ger' => 'de', 'gla' => 'gd', 'gle' => 'ga', 'glg' => 'gl', 'glv' => 'gv', 'gre' => 'el', 'grn' => 'gn',
        'guj' => 'gu', 'hat' => 'ht', 'hau' => 'ha', 'heb' => 'he', 'her' => 'hz', 'hin' => 'hi', 'hmo' => 'ho', 'hrv' => 'hr',
        'hun' => 'hu', 'hye' => 'hy', 'ibo' => 'ig', 'ice' => 'is', 'ido' => 'io', 'iii' => 'ii', 'iku' => 'iu', 'ile' => 'ie',
        'ina' => 'ia', 'ind' => 'id', 'ipk' => 'ik', 'isl' => 'is', 'ita' => 'it', 'jav' => 'jv', 'jpn' => 'ja', 'kal' => 'kl',
        'kan' => 'kn', 'kas' => 'ks', 'kat' => 'ka', 'kau' => 'kr', 'kaz' => 'kk', 'khm' => 'km', 'kik' => 'ki', 'kin' => 'rw',
        'kir' => 'ky', 'kom' => 'kv', 'kon' => 'kg', 'kor' => 'ko', 'kua' => 'kj', 'kur' => 'ku', 'lao' => 'lo', 'lat' => 'la',
        'lav' => 'lv', 'lim' => 'li', 'lin' => 'ln', 'lit' => 'lt', 'ltz' => 'lb', 'lub' => 'lu', 'lug' => 'lg', 'mac' => 'mk',
        'mah' => 'mh', 'mal' => 'ml', 'mao' => 'mi', 'mar' => 'mr', 'may' => 'ms', 'mkd' => 'mk', 'mlg' => 'mg', 'mlt' => 'mt',
        'mon' => 'mn', 'mri' => 'mi', 'msa' => 'ms', 'mya' => 'my', 'nau' => 'na', 'nav' => 'nv', 'nbl' => 'nr', 'nde' => 'nd',
        'ndo' => 'ng', 'nep' => 'ne', 'nld' => 'nl', 'nno' => 'nn', 'nob' => 'nb', 'nor' => 'no', 'nya' => 'ny', 'oci' => 'oc',
        'oji' => 'oj', 'ori' => 'or', 'orm' => 'om', 'oss' => 'os', 'pan' => 'pa', 'per' => 'fa', 'pli' => 'pi', 'pol' => 'pl',
        'por' => 'pt', 'pus' => 'ps', 'que' => 'qu', 'roh' => 'rm', 'ron' => 'ro', 'rum' => 'ro', 'run' => 'rn', 'rus' => 'ru',
        'sag' => 'sg', 'san' => 'sa', 'sin' => 'si', 'slk' => 'sk', 'slo' => 'sk', 'slv' => 'sl', 'sme' => 'se', 'smo' => 'sm',
        'sna' => 'sn', 'snd' => 'sd', 'som' => 'so', 'sot' => 'st', 'spa' => 'es', 'sqi' => 'sq', 'srd' => 'sc', 'srp' => 'sr',
        'ssw' => 'ss', 'sun' => 'su', 'swa' => 'sw', 'swe' => 'sv', 'tah' => 'ty', 'tam' => 'ta', 'tat' => 'tt', 'tel' => 'te',
        'tgk' => 'tg', 'tgl' => 'tl', 'tha' => 'th', 'tib' => 'bo', 'tir' => 'ti', 'ton' => 'to', 'tsn' => 'tn', 'tso' => 'ts',
        'tuk' => 'tk', 'tur' => 'tr', 'twi' => 'tw', 'uig' => 'ug', 'ukr' => 'uk', 'urd' => 'ur', 'uzb' => 'uz', 'ven' => 've',
        'vie' => 'vi', 'vol' => 'vo', 'wel' => 'cy', 'wln' => 'wa', 'wol' => 'wo', 'xho' => 'xh', 'yid' => 'yi', 'yor' => 'yo',
        'zha' => 'za', 'zho' => 'zh', 'zul' => 'zu',
    ];

    /** A BCP 47 tag from a stored code ("deu" → "de", "pcm" → "pcm", "en-GB" → "en-GB"); null for anything else. */
    public static function toBcp47(?string $code): ?string
    {
        $code = trim((string) $code);
        if (!preg_match('/\A([A-Za-z]{2,3})((?:-[A-Za-z0-9]{2,8})*)\z/', $code, $m)) {
            return null;
        }
        $language = strtolower($m[1]);
        $language = self::TWO_LETTER[$language] ?? $language;
        $subtags = array_values(array_filter(explode('-', $m[2]), static fn (string $s): bool => $s !== ''));
        foreach ($subtags as $i => $subtag) {
            // Region subtags are upper case, scripts title case, the rest lower.
            $subtags[$i] = match (true) {
                strlen($subtag) === 2 && ctype_alpha($subtag) => strtoupper($subtag),
                strlen($subtag) === 4 && ctype_alpha($subtag) => ucfirst(strtolower($subtag)),
                default => strtolower($subtag),
            };
        }
        return implode('-', [$language, ...$subtags]);
    }
}
