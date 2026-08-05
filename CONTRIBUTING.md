# Contributing

This module powers a production site — the Africa Multiple DRE Omeka S instance — so
changes are held to that bar. Bug reports and patches are welcome all the same.

## Reporting a bug

Open an issue with the Omeka S version, the PHP version, the module version (Admin →
Modules, or `config/module.ini`), and what you expected against what you got. For anything
touching metadata output, the most useful thing you can attach is the actual `<head>` of the
affected page, or the URL if the site is public.

## Making a change

```bash
git clone https://github.com/AM-Digital-Research-Environment/DRE-SEO.git
cd DRE-SEO
composer check
```

`composer check` syntax-checks every PHP and PHTML file and runs the unit harness. It needs
no `composer install` — the module has **no runtime dependencies**, by design. Omeka's core
`vendor/` supplies Laminas, PSR and Doctrine at runtime, and bundling duplicate copies of
framework packages causes class-collision fatals that take down the whole site, not just the
module. Never add `laminas/*` or `psr/*` to `composer.json`.

The integration smoke test runs the real service factories against an unpacked Omeka S:

```bash
OMEKA_PATH=/path/to/omeka-s composer integration
```

CI runs both on PHP 8.2 and 8.5, plus the smoke test against a checksum-pinned Omeka S
4.2.1. Please run `composer check` before pushing — it catches essentially everything CI
would, and saves a round-trip.

## Conventions

- Add a test to `tests/` for behaviour changes. The harness is deliberately dependency-free;
  follow the existing pattern rather than reaching for PHPUnit.
- New user-facing strings go through Omeka's translator and into `language/template.pot`.
- Escape everything in `.phtml` templates (`$this->escapeHtml()` and friends).
- Metadata correctness beats metadata volume. A wrong `schema.org` type or a mis-mapped
  citation field is worse for discovery than an absent one, because it teaches crawlers and
  reference managers something false.

## Releasing

Maintainers only:

1. Bump `version` in **`config/module.ini`** and **`CITATION.cff`** (and `date-released`).
2. Update `CHANGELOG.md`.
3. Commit, then tag: `git tag -a vX.Y.Z -m "..." && git push --follow-tags`.

The `Release` workflow refuses to publish if those two declared versions disagree with the
tag. When they agree it runs the full test suite, builds `DRESeo.zip` with
`git archive --prefix=DRESeo/`, checks the archive for leaked development files, and attaches
the zip plus a SHA-256 checksum to the release. Do not hand-build the zip.

## Licence

Contributions are accepted under [GPL-3.0-or-later](LICENSE), the licence of this project.
