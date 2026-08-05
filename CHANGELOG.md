# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions before 0.8.0 were tagged retroactively from the commit history; they had no
GitHub release and shipped no installable asset.

## [Unreleased]

### Added

- `LICENSE` — the GPL-3.0 text the module has declared since 0.1.0 in `composer.json`,
  `config/module.ini` and the README, but never shipped.
- `CITATION.cff`, so GitHub and Zenodo can render a citation for the module itself.
- `Release` workflow: a version tag now runs the test suite, checks that
  `config/module.ini` and `CITATION.cff` agree with the tag, builds `DRESeo.zip` via
  `git archive --prefix=DRESeo/`, verifies the archive carries no development files, and
  attaches the zip plus a SHA-256 checksum to the release.
- `CONTRIBUTING.md`, `SECURITY.md` and a Dependabot configuration for GitHub Actions.

### Changed

- `.gitattributes` now excludes `tests/` and repository furniture from release archives.
  The 0.9.0 zip carried 20 test files it had no use for. `.phtml` is declared as PHP so
  the language statistics stop reporting the view templates as HTML.
- CI checks out with `actions/checkout@v7`.

## [0.9.0] — 2026-08-04

### Fixed

- Controlled-vocabulary terms are no longer served as citable works. Templates 6
  (Authority Resource) and 23 (Journal) were absent from `template_kinds`, so every
  subject, language, genre, sponsor and journal fell through to the research-item kind —
  offering a Chicago citation, three style tabs and a BibTeX entry for a subject heading,
  and telling Zotero and Google Scholar the same through `citation_title` and
  `citation_abstract`. New `authority` and `journal` kinds name what was missing.
- `CitationMeta` no longer keeps its own list of non-citable kinds parallel to
  `CitationKind::isAuthorityRecord()`. Two lists in two files, one of them unchecked, could
  drift into disagreeing about whether a record was a work; the enum is now the single
  answer.

### Changed

- The `dreCitation` view helper distinguishes "not citable" from "citations are switched
  off", returning `['citable' => false, …]` rather than `null`, so a theme can retitle its
  panel without doing so on installations that lack this module.

### Added

- A test asserting that every template the site defines resolves to a kind — an absent key
  was the failure here, which the existing "the keys you gave are valid" test passed
  throughout.

## [0.8.0] — 2026-08-04

### Added

- Citations for the reader, not only for machines: Chicago, APA and MLA text on the record
  page through a `dreCitation` view helper, and BibTeX, RIS and CSL-JSON downloads at
  `/cite/{id}/{format}`. The panel and the Highwire/Dublin Core meta tags read the same
  properties through the same map, so they cannot disagree about what a record is.
- `CitationKind`, a closed enum: a kind cannot be added without also giving its CSL,
  BibTeX and RIS types. Adds project, section, conference, dataset, podcast and video.
- 47 tests over the value objects, the three styles, the three serialisations and the
  Omeka mapping; the smoke test now resolves the view helper through a real
  `HelperPluginManager`.

### Notes

- Authority records are not citable works: `/cite` returns 404 for them, matching the set
  `CitationMeta` already withholds Highwire tags from. (Incomplete for controlled
  vocabulary until 0.9.0.)
- `/cite` shares the `dre_seo_citation_meta` switch, so "citations off" means off
  everywhere.

## [0.7.0] — 2026-08-01

### Added

- An Omeka S integration smoke test that builds the module's real service factories,
  shared event listeners, configuration form and DBAL-backed sitemap generation against a
  checksum-pinned official Omeka S release.
- GitHub Actions CI on PHP 8.2 and the production PHP 8.5 runtime.

### Changed

- IndexNow pings coalesce through a `PingQueue`: concurrent edits become one background
  batch, and failed submissions are retained for a later retry.

## [0.6.0] — 2026-07-31

### Added

- A dependency-free unit harness (`composer check`) covering sitemap XML generation and
  caching, static-page override normalisation, the template mapping, `VideoObject` output
  and IndexNow key validation.

### Changed

- SEO services refactored; `PageSeoStore` normalises per-page overrides.

## [0.5.0] — 2026-06-25

### Fixed

- The home page declares the bare domain as its canonical instead of
  `/s/{slug}/page/{slug}`, matching the URL Google actually consolidates on and clearing
  the "duplicate, Google chose a different canonical" status. The pages sitemap lists it
  there too.
- JSON-LD is emitted as raw JSON via `noescape`, dropping the `//<!-- … //-->` comment
  guard Laminas `HeadScript` wraps inline script bodies in. `setAutoEscape()` governs only
  attribute and type escaping, so the previous call never removed it. `JSON_HEX_TAG`
  already escapes `</script>`.

## [0.4.0] — 2026-06-12

### Fixed

- Search Console structured-data errors. JSON-LD always emits a `description` (typed
  boilerplate fallback), fixing the critical "missing description" error on `Dataset`
  items; datasets expose authors as `creator`, where Google reads them.
- Book reviews (template 18) are typed `ScholarlyArticle` rather than `Review`. Scholarly
  reviews carry no `reviewRating`, so Google rejected the `Review` type for "missing
  itemReviewed". They keep full journal and article citation metadata.

### Added

- `license` emitted from `dcterms:license` when present; no default is asserted.

## [0.3.0] — 2026-06-05

### Added

- Podcast resource type (template 21) mapped to schema.org `PodcastEpisode` and a
  `podcast` citation kind. Zotero has no Highwire container tag for podcasts, so item-type
  detection rides on `DC.type=podcast`; host and guests ride `citation_author`, which
  Zotero folds onto the primary podcaster role.

## [0.2.0] — 2026-06-04

### Fixed

- Item-set landing pages stay indexable. `applyBrowse` marked every browse view
  `noindex, follow`, so Search Console rejected the sitemap it listed them in. Only browse
  variants carrying a query string — facets, pagination, sort — are noindexed now.
- The DOI is no longer duplicated into Zotero's Extra field. `DC.identifier` is the
  canonical page URL; the DOI is conveyed solely through `citation_doi`.

### Added

- `citation_editor`, so the Zotero Connector captures editors on books and book chapters
  (they live in `bibo:editorList`, and only `citation_author` was being emitted).

### Changed

- The pages sitemap follows the Omeka site navigation: menu order, priority by menu depth,
  the home page listed once at its canonical URL. Public pages unreachable from the
  navigation are still included, but demoted.

## [0.1.0] — 2026-06-03

### Added

- Initial release. Per-page title, meta description, Open Graph and Twitter cards and
  canonical links — derived automatically for resource pages, set by hand for static pages.
- schema.org JSON-LD typed from the resource template, plus `WebSite`/`SearchAction` on the
  home page and `BreadcrumbList` on resource pages.
- Highwire Press and Dublin Core meta tags for Zotero, Google Scholar and other reference
  managers.
- `/sitemap.xml` (index plus chunked per-type children) and `/robots.txt`.
- Google Search Console verification from a pasted snippet.
- Optional IndexNow ping on content changes.

Injection uses Omeka's head placeholder helpers via `view.show` / `view.layout` listeners —
no theme edits, no third-party Composer dependencies, no database tables.

[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/releases/tag/v0.8.0
[0.7.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/6dacce1
[0.6.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/5055f66
[0.5.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/3712426
[0.4.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/5a5f2bd
[0.3.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/9a03152
[0.2.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/a08e159
[0.1.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/58b9a6a
