<?php
declare(strict_types=1);

/**
 * Compiles language/*.po to the binary .mo catalogues Omeka loads at runtime.
 *
 * A tiny, dependency-free msgfmt: the GNU .mo format is a header, two string
 * tables and a (here empty) hash table. Written in PHP so the build works
 * anywhere the module's own test suite runs, without requiring gettext tools.
 *
 * The module ships no catalogue yet, only language/template.pot. With no .po
 * file there is nothing to compile, and that is not an error: a translator
 * adds language/<locale>.po (de_DE.po, say) and `composer i18n` then writes
 * the .mo beside it.
 *
 * Usage: php .github/scripts/compile-po.php
 */

$root = dirname(__DIR__, 2);

/**
 * @return array<string,string> msgid => msgstr (untranslated entries dropped)
 */
function parsePo(string $path): array
{
    $entries = [];
    $msgid = null;
    $msgstr = null;
    $current = null; // 'id' | 'str'

    $flush = static function () use (&$entries, &$msgid, &$msgstr): void {
        if ($msgid !== null && $msgid !== '' && $msgstr !== null && $msgstr !== '') {
            $entries[$msgid] = $msgstr;
        }
        $msgid = $msgstr = null;
    };

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            if ($line === '') {
                $flush();
                $current = null;
            }
            continue;
        }
        if (preg_match('/^msgid\s+"(.*)"$/s', $line, $m)) {
            $flush();
            $msgid = stripcslashes($m[1]);
            $current = 'id';
        } elseif (preg_match('/^msgstr\s+"(.*)"$/s', $line, $m)) {
            $msgstr = stripcslashes($m[1]);
            $current = 'str';
        } elseif (preg_match('/^"(.*)"$/s', $line, $m)) {
            // Continuation line.
            $chunk = stripcslashes($m[1]);
            if ($current === 'id') {
                $msgid .= $chunk;
            } elseif ($current === 'str') {
                $msgstr .= $chunk;
            }
        }
    }
    $flush();

    return $entries;
}

/** Serialise a msgid => msgstr map as a GNU .mo catalogue. */
function compileMo(array $entries, string $header): string
{
    // The header is stored under the empty msgid and must sort first.
    $entries = ['' => $header] + $entries;
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originalsOffset = 28;
    $translationsOffset = $originalsOffset + $count * 8;
    $hashOffset = $translationsOffset + $count * 8;

    $ids = '';
    $strs = '';
    $idTable = '';
    $strTable = '';

    // Strings live after the (zero-length) hash table.
    $idsBase = $hashOffset;
    foreach ($entries as $id => $str) {
        $idTable .= pack('VV', strlen((string) $id), $idsBase + strlen($ids));
        $ids .= $id . "\0";
    }
    $strsBase = $idsBase + strlen($ids);
    foreach ($entries as $str) {
        $strTable .= pack('VV', strlen((string) $str), $strsBase + strlen($strs));
        $strs .= $str . "\0";
    }

    return pack(
        'VVVVVVV',
        0x950412de,          // magic (little endian)
        0,                   // revision
        $count,
        $originalsOffset,
        $translationsOffset,
        0,                   // hash table size — readers fall back to binary search
        $hashOffset
    ) . $idTable . $strTable . $ids . $strs;
}

$compiled = 0;
foreach (glob($root . '/language/*.po') ?: [] as $po) {
    $locale = basename($po, '.po');
    $entries = parsePo($po);
    $header = "Project-Id-Version: DRE SEO\nMIME-Version: 1.0\n"
        . "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n"
        . 'Language: ' . $locale . "\nPlural-Forms: nplurals=2; plural=(n > 1);\n";
    file_put_contents($root . '/language/' . $locale . '.mo', compileMo($entries, $header));
    fwrite(STDOUT, sprintf("Compiled language/%s.mo (%d translated strings).\n", $locale, count($entries)));
    $compiled++;
}

if ($compiled === 0) {
    fwrite(STDOUT, "No language/*.po catalogue to compile.\n");
}
