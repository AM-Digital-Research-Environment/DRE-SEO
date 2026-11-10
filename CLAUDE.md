# DRE SEO

Omeka S module supplying the SEO, citation and sitemap layer for AMIRA, the Africa Multiple
DRE site (data.africamultiple.uni-bayreuth.de). `README.md` covers the architecture, every
setting and the schema.org / citation conventions; `OPERATIONS.md` the server side (upgrade,
the IndexNow cron); `CONTRIBUTING.md` the gates. Read the relevant section before changing
behaviour. This file is only the things that bite.

## The whole suite runs locally

`vendor/` holds the development tools (`composer install` once) and every check CI runs can
be run locally, the real-Omeka integration suite included. What makes the obvious commands
fail on the maintainers' Windows machines:

- `php` on PATH is **PHP 8.5**, possibly with no `php.ini` loaded, so without mbstring,
  openssl, curl, zip or pdo_sqlite: the unit suite then fails on `mb_substr()` and Composer
  cannot download. Point `PHPRC` at a directory whose `php.ini` sets `extension_dir` to the
  PHP install's `ext` folder and enables those extensions.
- Run Composer as `php /path/to/composer.phar …` with the PHP on PATH. A `composer` /
  `composer.bat` shim may forward to an older PHP, whose `@php` scripts then fail the 8.4
  platform check.
- `vendor/bin/phpunit` has no `.bat` shim: run `php vendor/bin/phpunit`.
- The integration suite needs `OMEKA_PATH` pointing at an Omeka S 4.2.1 tree with its
  vendor: the official release zip unpacked **outside** the repository (CI verifies it
  against the SHA-256 in `.github/workflows/ci.yml`). Never modify another module's
  checkout to serve it.

```bash
php /path/to/composer.phar check                                  # i18n:check + lint + analyse + unit tests
OMEKA_PATH=/path/to/omeka-s php /path/to/composer.phar test:integration
```

Run both before every commit that touches code, and say which ones you ran: CI covers the
8.4 floor the local 8.5 cannot, and a tag is not retractable. `composer i18n` regenerates
`language/template.pot` whenever a `// @translate` string is added, removed or moved to
another file (its references name files, not lines); commit the result.
`phpstan-baseline.neon` is a ledger of debt that predates analysis: delete entries as they
are fixed, never regenerate it to silence a new error.

Never edit the version by hand: `composer bump-version -- X.Y.Z` writes module.ini,
CITATION.cff and the CHANGELOG heading (`## [X.Y.Z] - YYYY-MM-DD`), and the release
workflow checks the same three files with `--check`. CONTRIBUTING.md has the release steps.

## Gotchas

- **Dispatch is by resource *template* id.** AMIRA's templates are 1:1 with its record
  kinds, so the schema.org map (`structured_data.template_types`) and the citation map
  (`citation.template_kinds`) key off template id — unlike IWAC SEO, which keys off
  resource class. The ids are pinned in `MongoDB2OmekaS/config/profiles/amira.json`;
  `tests/Fixture/amira-templates.php` fails the suite when a template is left unmapped.
- **`config/module.config.php` is wiring; `config/instance.config.php` is AMIRA's data**
  (the `dre_seo` block: template maps, landing pages, robots rules, citation styles …). They
  are combined with `+`, so their top-level keys must stay disjoint;
  `tests/Config/InstanceConfigTest` holds them so.
- **Head signals are two-phase.** The show/browse/page listeners write first, the
  `view.layout` listener gap-fills afterwards, and the first value written wins.
  `HeadWriter` (a shared service, one per request) owns the writes and the record of what
  was set; `HeadMetadata` is the policy. Write through `HeadWriter`, never through the head
  helpers directly, or the layout pass will write over you. DRE's own semantics differ
  from IWAC's in places (`max-image-preview:large` is a robots value any earlier directive
  keeps out; the first canonical stands): keep them.
- **Read settings through `SettingsGate`**, the one definition of "on" ('1', 1 or true).
- **`Module.php` is wiring.** What its listeners do lives in `src/Listener`, `src/Form` and
  the services; the listener method names in `attachListeners()` are asserted by tests, so
  keep them as thin delegates.
- **IndexNow skips a bulk change.** A drain that finds more than 200 never-sent URLs
  (`PingQueue::BULK_THRESHOLD`) discards them unsent, because a MongoDB2OmekaS sync re-saves
  thousands. A test that queues more than that is testing the guard.
- **Tests run against shims, not Omeka.** `tests/Shim/omeka.php` carries only the surface
  the module touches, guarded by `class_exists()`; the renderer there records the head.
  A test that needs more than the shim belongs in `tests/Integration`, against the real
  classes, not in a bigger shim. PHPStan treats the Omeka boundary as opaque, so a missing
  `use` of a module class in a factory passes analysis and only the integration suite
  catches it.
- **No runtime dependencies, no bundled `vendor/`.** Never add `laminas/*` or `psr/*` to
  `composer.json`: Omeka's vendor provides them, and a duplicate takes the site down.
- **Contracts with other modules.** DRE-theme calls `dreCitation($item)` and
  `dreBreadcrumb($resource)` and relies on their names, arguments and return shapes; DRE
  Linked Data's `dreLinkedData` helper is read through `LinkedDataIdentity` (`uri`,
  `downloads`, `sameAs`, `dataset()`), which both modules rely on for one identity per
  record. DRE Search's results page is `/s/{slug}/dre-search` and renders from
  `/dre-search/api/*`: robots.txt must never block those.
- The module folder must be named `DRESeo` inside Omeka's `modules/`; the namespace and the
  release zip's prefix depend on it.

## Conventions

PSR-12, 4-space, LF. British English in prose and comments (`optimisation`, `serialiser`),
except where an identifier mirrors a vocabulary term (schema.org `Organization`). Comments
explain *why* in full sentences. Translatable strings end with `// @translate` in PHP and go
through `$this->translate()` in `.phtml`. A behaviour change comes with a test that fails
without it and a bullet under `## [Unreleased]` in `CHANGELOG.md`.
