<?php
declare(strict_types=1);

namespace DRESeo\Test\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * .github/scripts/compile-po.php on a scratch catalogue: what reaches the .mo
 * Omeka loads, read back with a minimal .mo reader.
 */
final class CompilePoTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dre-seo-po-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/language', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/language/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/language');
        @rmdir($this->root);
    }

    public function testTheCatalogueKeepsItsHeaderContextAndPluralsAndDropsFuzzyEntries(): void
    {
        file_put_contents($this->root . '/language/de_DE.po', implode("\n", [
            'msgid ""',
            'msgstr ""',
            '"Language: de_DE\n"',
            '"Plural-Forms: nplurals=2; plural=(n != 1);\n"',
            '',
            'msgid "Save"',
            'msgstr "Speichern"',
            '',
            '#, fuzzy',
            'msgid "Regenerate"',
            'msgstr "Neu erzeugen?"',
            '',
            'msgctxt "button"',
            'msgid "Open"',
            'msgstr "Öffnen"',
            '',
            'msgid "%d item"',
            'msgid_plural "%d items"',
            'msgstr[0] "%d Objekt"',
            'msgstr[1] "%d Objekte"',
            'msgid "Untranslated"',
            'msgstr ""',
            '',
        ]));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/.github/scripts/compile-po.php')
            . ' ' . escapeshellarg('--root=' . $this->root), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        $entries = self::readMo((string) file_get_contents($this->root . '/language/de_DE.mo'));
        $this->assertStringContainsString('plural=(n != 1)', $entries[''], "the catalogue's own Plural-Forms");
        $this->assertSame('Speichern', $entries['Save']);
        $this->assertArrayNotHasKey('Regenerate', $entries, 'a fuzzy entry is not a translation');
        $this->assertSame('Öffnen', $entries["button\x04Open"]);
        $this->assertSame("%d Objekt\0%d Objekte", $entries["%d item\0%d items"]);
        $this->assertArrayNotHasKey('Untranslated', $entries);
    }

    /** @return array<string,string> */
    private static function readMo(string $mo): array
    {
        $header = unpack('Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', $mo);
        self::assertIsArray($header);
        $entries = [];
        for ($i = 0; $i < $header['count']; $i++) {
            $id = unpack('Vlength/Voffset', $mo, $header['originals'] + $i * 8);
            $str = unpack('Vlength/Voffset', $mo, $header['translations'] + $i * 8);
            self::assertIsArray($id);
            self::assertIsArray($str);
            $entries[substr($mo, $id['offset'], $id['length'])] = substr($mo, $str['offset'], $str['length']);
        }
        return $entries;
    }
}
