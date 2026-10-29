<?php
declare(strict_types=1);

/**
 * Regenerates language/template.pot from the module sources.
 *
 * Two conventions are scanned, matching Omeka S:
 *   • PHP  — a string literal on a line ending in `// @translate`
 *   • .phtml — `$this->translate('…')` / `translate("…")` calls
 *
 * `#:` references carry the file path only, deliberately — no line numbers.
 * With them, the template would go stale whenever an edit merely *moved* a
 * string: deleting one blank line above a marked literal shifts every later
 * reference in the file, and --check would fail without a single msgid
 * changing. Paths alone keep --check meaningful — it fails when the set of
 * translatable strings actually changes.
 *
 * The same script runs in IWAC SEO; keep the two in step.
 *
 * Usage: php .github/scripts/extract-strings.php [--check]
 *   (or `composer i18n` / `composer i18n:check`)
 *   --check exits non-zero when the committed template is out of date
 *   (so CI catches a new string that was never extracted).
 */

$root = dirname(__DIR__, 2);
$target = $root . '/language/template.pot';

/** @var array<string,string[]> msgid => ["relative/path", …] */
$strings = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        // .integration holds the Omeka S checkout the integration suite runs
        // against — gitignored, and created by CI's own integration job. Its
        // several thousand translatable strings are Omeka's, not this module's.
        // .claude is where Claude Code keeps local git worktrees: each holds a
        // full copy of the module, so scanning it would add every string again
        // under a second path and fail --check on a clean tree.
        static function (SplFileInfo $file): bool {
            $name = $file->getFilename();
            if ($file->isDir()) {
                return !in_array(
                    $name,
                    ['vendor', '.git', 'node_modules', '.github', 'tests', '.integration', '.claude'],
                    true
                );
            }
            return in_array($file->getExtension(), ['php', 'phtml'], true);
        }
    )
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $path = $file->getPathname();
    // Forward slashes whatever the OS: the reference is a portable file
    // identity, and a Windows-flavoured template would otherwise differ
    // from a Linux one on every line without a single string changing.
    $relative = str_replace('\\', '/', ltrim(str_replace($root, '', $path), '/\\'));
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }
    foreach ($lines as $line) {
        $found = [];

        // `'Some text', // @translate` — take the last literal before the marker.
        if (preg_match('/^(.*)\/\/\s*@translate\s*$/', $line, $m)
            && preg_match_all('/(?<!\\\\)([\'"])((?:\\\\.|(?!\1).)*)\1/', $m[1], $lit, PREG_SET_ORDER)
        ) {
            $last = end($lit);
            $found[] = [$last[1], $last[2]];
        }

        // translate('…') calls in templates (and anywhere else).
        if (preg_match_all('/translate\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/', $line, $calls, PREG_SET_ORDER)) {
            foreach ($calls as $call) {
                $found[] = [$call[1], $call[2]];
            }
        }

        foreach ($found as [$quote, $raw]) {
            $value = $quote === "'"
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], $raw)
                : stripcslashes($raw);
            if (trim($value) === '') {
                continue;
            }
            $strings[$value][] = $relative;
        }
    }
}

ksort($strings, SORT_STRING);

$escape = static function (string $value): string {
    return str_replace(
        ['\\', '"', "\t", "\n"],
        ['\\\\', '\\"', '\\t', '\\n'],
        $value
    );
};

$out = <<<HEAD
# DRE SEO – translation template
#
# This file is distributed under the same license as the DRE SEO module.
# Regenerate with: composer i18n
#
msgid ""
msgstr ""
"Project-Id-Version: DRE SEO\\n"
"Report-Msgid-Bugs-To: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: \\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"


HEAD;

foreach ($strings as $msgid => $references) {
    sort($references, SORT_STRING);
    foreach (array_unique($references) as $reference) {
        $out .= '#: ' . $reference . "\n";
    }
    $out .= 'msgid "' . $escape($msgid) . '"' . "\n";
    $out .= 'msgstr ""' . "\n\n";
}

if (in_array('--check', $argv, true)) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current !== $out) {
        fwrite(STDERR, "language/template.pot is out of date — run composer i18n\n");
        exit(1);
    }
    fwrite(STDOUT, sprintf("language/template.pot is up to date (%d strings).\n", count($strings)));
    exit(0);
}

file_put_contents($target, $out);
fwrite(STDOUT, sprintf("Wrote %s (%d strings).\n", $target, count($strings)));
