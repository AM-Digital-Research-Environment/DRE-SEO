<?php
declare(strict_types=1);

/**
 * Sets the module's version everywhere it is stated, or checks that every
 * statement agrees with one.
 *
 * The version lives in config/module.ini (what Omeka reads), CITATION.cff
 * (with its date-released) and the CHANGELOG heading the release notes are
 * taken from. Edited by hand, one of them was eventually forgotten; the theme,
 * Visualizations and Search each script the bump, and this is the same
 * behaviour without a Node toolchain. The release workflow runs the --check
 * form on the tag, so the files this writes and the files it checks are one
 * list.
 *
 * Usage:
 *   php .github/scripts/bump-version.php 0.17.0 [--date=2026-11-10]
 *       Writes the version into module.ini and CITATION.cff, turns
 *       `## [Unreleased]` into `## [0.17.0] - <date>` under a fresh empty
 *       Unreleased section, and updates the compare links at the foot of the
 *       CHANGELOG. Refuses an empty Unreleased section, a version that is not
 *       above the current one, and a version the CHANGELOG already has.
 *       It commits and tags nothing.
 *   php .github/scripts/bump-version.php --check 0.17.0
 *       Exits 1, naming each file, when any of them states another version.
 *
 * `--root=DIR` works on another checkout (the tests use it). Also available as
 * `composer bump-version -- 0.17.0`.
 */

const REPOSITORY = 'https://github.com/AM-Digital-Research-Environment/DRE-SEO';

/** @param list<string> $argv */
function main(array $argv): int
{
    $root = dirname(__DIR__, 2);
    $date = date('Y-m-d');
    $check = false;
    $version = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $check = true;
        } elseif (str_starts_with($arg, '--root=')) {
            $root = substr($arg, 7);
        } elseif (str_starts_with($arg, '--date=')) {
            $date = substr($arg, 7);
        } elseif ($version === null && !str_starts_with($arg, '--')) {
            $version = $arg;
        } else {
            return fail("unexpected argument '{$arg}'");
        }
    }

    if ($version === null) {
        return fail('usage: bump-version.php [--check] X.Y.Z [--date=YYYY-MM-DD] [--root=DIR]');
    }
    $version = ltrim($version, 'v');
    if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
        return fail("'{$version}' is not a semantic version (X.Y.Z or X.Y.Z-pre)");
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return fail("'{$date}' is not a YYYY-MM-DD date");
    }

    $files = [
        'ini' => $root . '/config/module.ini',
        'cff' => $root . '/CITATION.cff',
        'changelog' => $root . '/CHANGELOG.md',
    ];
    $text = [];
    foreach ($files as $key => $path) {
        $content = @file_get_contents($path);
        if ($content === false) {
            return fail("cannot read {$path}");
        }
        $text[$key] = $content;
    }

    return $check ? check($version, $text) : bump($version, $date, $text, $files);
}

/**
 * @param array<string,string> $text
 */
function check(string $version, array $text): int
{
    $problems = [];
    $ini = iniVersion($text['ini']);
    if ($ini !== $version) {
        $problems[] = ['config/module.ini', "version is '{$ini}', expected '{$version}'"];
    }
    $cff = cffVersion($text['cff']);
    if ($cff !== $version) {
        $problems[] = ['CITATION.cff', "version is '{$cff}', expected '{$version}'"];
    }
    if (!preg_match('/^## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}$/m', $text['changelog'])) {
        $problems[] = ['CHANGELOG.md', "no '## [{$version}] - YYYY-MM-DD' section"];
    }

    foreach ($problems as [$file, $message]) {
        fwrite(STDERR, getenv('GITHUB_ACTIONS') ? "::error file={$file}::{$message}\n" : "{$file}: {$message}\n");
    }
    if ($problems !== []) {
        return 1;
    }
    echo "module.ini, CITATION.cff and CHANGELOG.md all state {$version}.\n";
    return 0;
}

/**
 * @param array<string,string> $text
 * @param array<string,string> $files
 */
function bump(string $version, string $date, array $text, array $files): int
{
    $current = iniVersion($text['ini']);
    if ($current === null) {
        return fail('config/module.ini states no version');
    }
    if (version_compare($version, $current, '<=')) {
        return fail("{$version} is not above the current version {$current}");
    }

    $changelog = $text['changelog'];
    if (str_contains($changelog, "## [{$version}]")) {
        return fail("CHANGELOG.md already has a {$version} section");
    }
    if (!preg_match('/^## \[Unreleased\][ \t]*\n(.*?)(?=^## |\z)/ms', $changelog, $m)) {
        return fail('CHANGELOG.md has no ## [Unreleased] section');
    }
    // A link-reference block at the foot is not release notes.
    $notes = preg_replace('/^\[[^\]]+\]: \S+$/m', '', $m[1]) ?? '';
    if (trim($notes) === '') {
        return fail('the Unreleased section of CHANGELOG.md is empty: nothing to release');
    }
    $changelog = (string) preg_replace(
        '/^## \[Unreleased\][ \t]*$/m',
        "## [Unreleased]\n\n## [{$version}] - {$date}",
        $changelog,
        1
    );
    // The compare links: Unreleased now starts from the new tag, which gets
    // the range from the previous one. A CHANGELOG without them is left so.
    $unreleasedLink = '[Unreleased]: ' . REPOSITORY . "/compare/v{$current}...HEAD";
    if (str_contains($changelog, $unreleasedLink)) {
        $changelog = str_replace(
            $unreleasedLink,
            '[Unreleased]: ' . REPOSITORY . "/compare/v{$version}...HEAD\n"
                . "[{$version}]: " . REPOSITORY . "/compare/v{$current}...v{$version}",
            $changelog
        );
    }

    $ini = replaceOnce('/^(version\s*=\s*")[^"]*(")/m', '${1}' . $version . '${2}', $text['ini'], 'config/module.ini version');
    $cff = replaceOnce('/^version:.*$/m', "version: {$version}", $text['cff'], 'CITATION.cff version');
    $cff = $cff === null ? null : replaceOnce('/^date-released:.*$/m', "date-released: \"{$date}\"", $cff, 'CITATION.cff date-released');
    if ($ini === null || $cff === null) {
        return 1;
    }

    file_put_contents($files['ini'], $ini);
    file_put_contents($files['cff'], $cff);
    file_put_contents($files['changelog'], $changelog);
    echo "{$current} -> {$version} ({$date}) in config/module.ini, CITATION.cff and CHANGELOG.md.\n"
        . "Review the diff, commit it as \"Release {$version}\", then tag v{$version} and push the tag.\n";
    return 0;
}

function iniVersion(string $ini): ?string
{
    return preg_match('/^version\s*=\s*"([^"]*)"/m', $ini, $m) ? $m[1] : null;
}

function cffVersion(string $cff): ?string
{
    return preg_match('/^version:\s*"?([^"\s]+)"?\s*$/m', $cff, $m) ? $m[1] : null;
}

/** The text with the one match of $pattern replaced, or null (reported) when it does not match exactly once. */
function replaceOnce(string $pattern, string $replacement, string $text, string $what): ?string
{
    $count = 0;
    $out = preg_replace($pattern, $replacement, $text, -1, $count);
    if ($out === null || $count !== 1) {
        fail("expected one {$what} line, found {$count}");
        return null;
    }
    return $out;
}

function fail(string $message): int
{
    fwrite(STDERR, "bump-version: {$message}\n");
    return 1;
}

exit(main($argv));
