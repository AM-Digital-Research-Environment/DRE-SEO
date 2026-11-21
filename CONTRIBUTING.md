# Contributing

This module powers a production site — the Africa Multiple DRE Omeka S instance — so
changes are held to that bar. Bug reports and patches are welcome all the same.

## Reporting a bug

Open an issue with the Omeka S version, the PHP version, the module version (Admin →
Modules, or `config/module.ini`), and what you expected against what you got. For anything
touching metadata output, the most useful thing you can attach is the actual `<head>` of the
affected page, or the URL if the site is public.

## Making a change

You need PHP 8.4 or later and Composer.

```bash
git clone https://github.com/AM-Digital-Research-Environment/DRE-SEO.git
cd DRE-SEO
composer install
OMEKA_PATH=/path/to/omeka-s composer check
```

`composer install` fetches the **development tools only** — PHPUnit, PHPStan and
PHP_CodeSniffer, into a `vendor/` that is never committed (nor is `composer.lock`). The
module itself has **no runtime dependencies**, by design. Omeka's core `vendor/` supplies
Laminas, PSR and Doctrine at runtime, and bundling duplicate copies of framework packages
causes class-collision fatals that take down the whole site, not just the module. Never add
`laminas/*` or `psr/*` to `composer.json`, not even under `require-dev`.

`composer check` runs, in order:

| Script | What it checks |
|---|---|
| `composer i18n:check` | `language/template.pot` still matches the strings the sources mark for translation |
| `composer lint` | PSR-12, through PHP_CodeSniffer (`composer lint:fix` repairs what it can) |
| `composer analyse` | PHPStan at level 6, against the recorded baseline: the module and `tests/Integration` against the Omeka tree in `OMEKA_PATH` (`phpstan.neon.dist`), the unit suite against the shim (`phpstan.unit.neon.dist`) |
| `composer test` | the PHPUnit unit suite (`tests/`, configured in `phpunit.xml.dist`) |

The unit suite runs without Omeka: `tests/Shim/omeka.php` gives the few Omeka, Laminas and
Doctrine classes the module touches minimal stand-ins, each guarded by `class_exists()` so a
real class always wins. The integration suite (`tests/Integration`) runs the module against
the real framework instead — its service factories, listeners, forms, Laminas head helpers
and the sitemap SQL — and needs an installed Omeka S 4.2.1 (the official release zip, or a
checkout after `composer install --no-dev`), as `composer analyse` does:

```bash
OMEKA_PATH=/path/to/omeka-s composer test:integration
```

Its bootstrap loads Omeka's `vendor/autoload.php` before the module's development vendor, so
the Laminas and Doctrine classes under test are the ones production loads.

CI runs the syntax sweep and the unit suite on PHP 8.4 and 8.5, the translation, PSR-12 and
PHPStan gates at the 8.4 floor, and the integration suite against the checksum-pinned
official Omeka S 4.2.1 release. Please run `composer check` before pushing — it runs every
gate CI does except the `php -l` sweep, `composer validate --strict` and the integration
suite, and saves a round trip;
run `composer test:integration` too when a change touches `Module.php`, `src/Listener/`, a
factory, a controller or the configuration. [CLAUDE.md](CLAUDE.md) lists what bites on the
maintainer's machine (PHP 8.5 on PATH, Composer through its phar).

## Conventions

- A behaviour change comes with a test that fails without it: in `tests/Service/` (or the
  directory of the class under test) for logic that runs on the shims, in
  `tests/Integration/` for anything that depends on real Omeka or Laminas behaviour. Shared
  stand-ins live in `tests/Double/`; extend `tests/Shim/omeka.php` only by the surface a test
  actually needs.
- **The PHPStan baseline is a ledger, not a suppression list.** `phpstan-baseline.neon`
  records the findings that predate analysis; new and changed code meets level 6 in full.
  Delete an entry when you fix it, and never regenerate the file to make a new error go
  away. If regenerating is ever warranted, comment its `includes` line out of both
  `phpstan.neon.dist` and `phpstan.unit.neon.dist` first — otherwise the run silently drops
  the recorded debt — merge what the two passes write, and read the diff.
- **A new resource template on the site** fails the daily `Templates` workflow. Add it to
  `tests/Fixture/amira-templates.php`; the suite then fails until
  `structured_data.template_types` and `citation.template_kinds` in
  `config/instance.config.php` both name it.
- New user-facing strings go through Omeka's translator: `// @translate` at the end of the
  line in PHP, `$this->translate()` in `.phtml`. Then run `composer i18n` and commit the
  regenerated `language/template.pot`; `composer i18n:check` fails until you do.
- Escape everything in `.phtml` templates (`$this->escapeHtml()` and friends).
- Metadata correctness beats metadata volume. A wrong `schema.org` type or a mis-mapped
  citation field is worse for discovery than an absent one, because it teaches crawlers and
  reference managers something false.

## Releasing

Maintainers only:

1. Fill `## [Unreleased]` in `CHANGELOG.md` as you work.
2. On a clean, green `main`, run `composer bump-version -- X.Y.Z` (or
   `php .github/scripts/bump-version.php X.Y.Z`; `--date=YYYY-MM-DD` overrides today). It
   writes the version into **`config/module.ini`** and **`CITATION.cff`** (with
   `date-released`), turns `## [Unreleased]` into `## [X.Y.Z] - YYYY-MM-DD` under a fresh,
   empty Unreleased section, and moves the compare links at the foot of the file. It refuses
   an empty Unreleased section and a version that is not above the current one, and it
   commits and tags nothing.
3. Review the diff, commit it as `Release X.Y.Z`, then tag and push:
   `git tag -a vX.Y.Z -m "Release X.Y.Z" && git push origin main vX.Y.Z`.

The `Release` workflow first runs the whole of `ci.yml` on the tag — the unit suite on PHP
8.4 and 8.5, the translation, PSR-12 and PHPStan gates and the integration suite against
Omeka S 4.2.1. It then checks, with `bump-version.php --check`, that module.ini,
CITATION.cff and a dated CHANGELOG section all state the tag's version, builds `DRESeo.zip`
with `git archive --prefix=DRESeo/`, checks the archive for leaked development files, and
attaches the zip plus a SHA-256 checksum to a release whose notes are the CHANGELOG
section. Do not edit the version by hand, and do not hand-build the zip.

## Licence

Contributions are accepted under [GPL-3.0-or-later](LICENSE), the licence of this project.
