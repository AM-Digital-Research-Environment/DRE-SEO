<?php
declare(strict_types=1);

/**
 * Compiles language/*.po to the binary .mo catalogues Omeka loads at runtime
 * (config/module.config.php registers language/%s.mo with the translator).
 *
 * A tiny, dependency-free msgfmt: the GNU .mo format is a header, two string
 * tables and a (here empty) hash table. Written in PHP so the build works
 * anywhere the module's own test suite runs, without requiring gettext tools.
 * It follows msgfmt where a catalogue can go wrong: the catalogue's own header
 * (its Plural-Forms above all) is kept, an entry flagged fuzzy is left out, a
 * msgctxt is stored as context, and a plural entry keeps every form.
 *
 * The module ships no catalogue yet, only language/template.pot. With no .po
 * file there is nothing to compile, and that is not an error: a translator
 * adds language/<locale>.po (de_DE.po, say) and `composer i18n` then writes
 * the .mo beside it.
 *
 * Usage: php .github/scripts/compile-po.php [--root=DIR]
 */

$root = dirname(__DIR__, 2);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, 7);
    }
}

/**
 * The catalogue's translated entries, keyed as a .mo stores them: the msgid,
 * "msgctxt\x04msgid" with a context, "msgid\0msgid_plural" for a plural (whose
 * value is its forms joined by NUL). The header is the entry under ''.
 *
 * @return array<string,string>
 */
function parsePo(string $path): array
{
    $entries = [];
    $entry = [];
    $field = null; // the field a continuation line extends
    $fuzzy = false;

    $flush = static function () use (&$entries, &$entry, &$field, &$fuzzy): void {
        $id = $entry['msgid'] ?? null;
        if ($id !== null && !$fuzzy) {
            $key = isset($entry['msgctxt']) ? $entry['msgctxt'] . "\x04" . $id : $id;
            if (isset($entry['msgid_plural'])) {
                $forms = [];
                for ($n = 0; isset($entry['msgstr[' . $n . ']']); $n++) {
                    $forms[] = $entry['msgstr[' . $n . ']'];
                }
                if ($forms !== [] && !in_array('', $forms, true)) {
                    $entries[$key . "\0" . $entry['msgid_plural']] = implode("\0", $forms);
                }
            } elseif (($entry['msgstr'] ?? '') !== '') {
                $entries[$key] = $entry['msgstr'];
            }
        }
        $entry = [];
        $field = null;
        $fuzzy = false;
    };

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            $flush();
            continue;
        }
        if (str_starts_with($line, '#')) {
            // A comment starts the next entry; its flags may mark it fuzzy.
            if ($field !== null) {
                $flush();
            }
            if (str_starts_with($line, '#,') && preg_match('/\bfuzzy\b/', $line)) {
                $fuzzy = true;
            }
            continue;
        }
        if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/s', $line, $m)) {
            $startsEntry = $m[1] === 'msgctxt' || $m[1] === 'msgid';
            if ($startsEntry && (isset($entry['msgstr']) || isset($entry['msgstr[0]']))) {
                // A new entry with no blank line or comment before it.
                $flush();
            }
            $field = $m[1];
            $entry[$field] = stripcslashes($m[2]);
        } elseif ($field !== null && preg_match('/^"(.*)"$/s', $line, $m)) {
            $entry[$field] .= stripcslashes($m[1]);
        }
    }
    $flush();

    return $entries;
}

/**
 * Serialise entries as a GNU .mo catalogue.
 *
 * @param array<string,string> $entries
 */
function compileMo(array $entries): string
{
    // The header is stored under the empty msgid and must sort first.
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
    if (!isset($entries[''])) {
        // No header of its own: a minimal one, with the English plural rule
        // template.pot declares. A translator's header always wins.
        $entries[''] = "Project-Id-Version: DRE SEO\nMIME-Version: 1.0\n"
            . "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n"
            . 'Language: ' . $locale . "\nPlural-Forms: nplurals=2; plural=(n != 1);\n";
    }
    file_put_contents($root . '/language/' . $locale . '.mo', compileMo($entries));
    fwrite(STDOUT, sprintf("Compiled language/%s.mo (%d translated strings).\n", $locale, count($entries) - 1));
    $compiled++;
}

if ($compiled === 0) {
    fwrite(STDOUT, "No language/*.po catalogue to compile.\n");
}
