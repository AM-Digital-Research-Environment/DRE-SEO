<?php
declare(strict_types=1);

/**
 * Compares the resource templates the live AMIRA site lists with
 * tests/Fixture/amira-templates.php.
 *
 * The configuration tests hold every template map to that fixture, but the
 * fixture is copied by hand, and nothing in the repository learns that the
 * site has gained a template: 33 and 34 went live unmapped because no one
 * added them to it. The dashboard lists unmapped templates, which helps only
 * someone who looks. The scheduled `templates` workflow runs this, so a new,
 * removed or relabelled template fails a run and its owner is told; adding it
 * to the fixture then fails the suite until every map names it.
 *
 * Usage:
 *   php .github/scripts/check-templates.php [--api=URL] [--from=FILE]
 *       Exits 1, naming each template, when the site and the fixture differ
 *       by id or label; 2 when the site cannot be read. `--api` is the API
 *       root (the AMIRA site by default); `--from` reads a saved
 *       /api/resource_templates response instead (the tests use it).
 */

const DEFAULT_API = 'https://data.africamultiple.uni-bayreuth.de/api';

/** @param list<string> $argv */
function main(array $argv): int
{
    $api = DEFAULT_API;
    $from = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--api=')) {
            $api = rtrim(substr($arg, 6), '/');
        } elseif (str_starts_with($arg, '--from=')) {
            $from = substr($arg, 7);
        } else {
            fwrite(STDERR, "check-templates: unexpected argument '{$arg}'\n");
            return 2;
        }
    }

    try {
        $site = $from !== null ? readSaved($from) : fetchTemplates($api);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'check-templates: ' . $e->getMessage() . "\n");
        return 2;
    }
    $fixture = [];
    foreach (require dirname(__DIR__, 2) . '/tests/Fixture/amira-templates.php' as $group) {
        $fixture += $group;
    }

    $problems = compareTemplates($site, $fixture);
    if ($problems === []) {
        echo 'check-templates: the fixture lists all ' . count($site) . " templates of the site.\n";
        return 0;
    }
    foreach ($problems as $problem) {
        echo $problem . "\n";
    }
    echo "Update tests/Fixture/amira-templates.php, then map each new template in config/instance.config.php.\n";
    return 1;
}

/**
 * What differs between the site's templates and the fixture's, one line each.
 *
 * @param array<int,string> $site
 * @param array<int,string> $fixture
 * @return list<string>
 */
function compareTemplates(array $site, array $fixture): array
{
    $problems = [];
    foreach ($site as $id => $label) {
        if (!array_key_exists($id, $fixture)) {
            $problems[] = sprintf('Template %d (%s) is on the site but not in the fixture.', $id, $label);
        } elseif ($fixture[$id] !== $label) {
            $problems[] = sprintf('Template %d is "%s" on the site but "%s" in the fixture.', $id, $label, $fixture[$id]);
        }
    }
    foreach ($fixture as $id => $label) {
        if (!array_key_exists($id, $site)) {
            $problems[] = sprintf('Template %d (%s) is in the fixture but no longer on the site.', $id, $label);
        }
    }
    return $problems;
}

/**
 * Every template the API lists, page by page.
 *
 * @return array<int,string>
 */
function fetchTemplates(string $api): array
{
    $templates = [];
    for ($page = 1; $page <= 50; $page++) {
        $url = $api . '/resource_templates?per_page=100&page=' . $page;
        $context = stream_context_create(['http' => [
            'timeout' => 30,
            'header' => "Accept: application/json\r\nUser-Agent: DRE-SEO template check\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException("cannot read {$url}");
        }
        $batch = parseTemplates($body, $url);
        if ($batch === []) {
            break;
        }
        $templates += $batch;
    }
    ksort($templates);
    return $templates;
}

/** @return array<int,string> */
function readSaved(string $file): array
{
    $body = @file_get_contents($file);
    if ($body === false) {
        throw new \RuntimeException("cannot read {$file}");
    }
    return parseTemplates($body, $file);
}

/** @return array<int,string> */
function parseTemplates(string $body, string $source): array
{
    $rows = json_decode($body, true);
    if (!is_array($rows)) {
        throw new \RuntimeException("{$source} is not a JSON list of resource templates");
    }
    $templates = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['o:id'])) {
            throw new \RuntimeException("{$source} is not a JSON list of resource templates");
        }
        $templates[(int) $row['o:id']] = (string) ($row['o:label'] ?? '');
    }
    return $templates;
}

exit(main($argv));
