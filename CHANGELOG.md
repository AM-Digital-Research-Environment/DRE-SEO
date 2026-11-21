# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions before 0.8.0 were tagged retroactively from the commit history; they had no
GitHub release and shipped no installable asset.

## [Unreleased]

### Changed

- A save no longer clears the sitemap cache. It records the time of the change, and a
  cached sitemap built before it is rebuilt once the site has gone five minutes without
  another, so a MongoDB2OmekaS sync of thousands of records rebuilds the sitemap once,
  after it ends, rather than on the next crawl after every save. The dashboard's
  Regenerate and saving the static-page overrides still clear it at once.
- PHPStan analyses the module and the integration suite against the real Omeka S 4.2.1
  tree, so `composer analyse` (and `composer check`) needs `OMEKA_PATH`. The global ignores
  of `class.notFound`, `interface.notFound`, `method.notFound`, `staticMethod.notFound` and
  `argument.type` are gone; the unit suite is analysed separately, against the shim it
  runs on (`phpstan.unit.neon.dist`). The admin and response controllers check that they
  hold an HTTP request and response instead of assuming it.

### Added

- A daily `Templates` workflow compares `tests/Fixture/amira-templates.php` with the
  resource templates the live site lists and fails when the site gains, drops or relabels
  one, so a new template can no longer go live unmapped unnoticed.

## [0.18.0] - 2026-11-20

Citations, dates and page numbers come out right for the records Zotero and Google Scholar
read, the Series and Cluster Chronicles templates are mapped, and a staging copy no longer
pings IndexNow. After replacing the module folder, run **Admin → Modules → DRE SEO →
Upgrade**. Then add the Series (45028) and Cluster Chronicles (50105) item sets to the AMIRA
site under **Sites → AMIRA → Resources**: until then Omeka keeps their pages out of the
index, and the sitemap leaves them out.

### Changed

- The export type of a descriptive record (CSL `webpage`, BibTeX `@online`, RIS `ELEC`, Open
  Graph `website`) follows from `CitationKind::isAuthorityRecord()` instead of five lists of
  the same seven kinds.
- The IndexNow batch size is `PingQueue::CAP` alone: the outbox's lease limit and the
  interface's default follow it, so raising it no longer leaves claims silently capped at
  200.
- Tests: the unit suite's stand-in for Omeka's values renders a linked value as Omeka does
  (its record's URL) and knows a private value, so the rule that a private identifier is no
  `sameAs` (0.17.0) is now tested.
- The release workflow's archive check reads the `export-ignore` paths from
  `.gitattributes` itself, so `CITATION.cff`, `CONTRIBUTING.md` and `SECURITY.md` are checked
  too, and fails a zip in which `src/`, `view/`, `asset/`, `config/`, `language/` or
  `scripts/` holds no file.
- `composer.json` declares the extensions the module uses, `ext-mbstring` and `ext-ctype`;
  `ext-dom`, which only the tests use, moves to `require-dev`.

### Fixed

- **A date interval is cited as a span, not as its first day.** 625 AMIRA records date
  themselves by a NumericDataTypes interval (`1950-01-01/1962-01-01`), which every output
  read as 1 January 1950. The text styles now print `1950–1962`, CSL-JSON a date range,
  biblatex `date = {1950-01-01/1962-01-01}`; the formats that hold one date
  (`citation_publication_date`, `DC.date`, RIS, the JSON-LD dates) carry only what the two
  ends share, here `1950`.
- A month or day that cannot exist (`1999-20`, `2018-02-30`) is no longer emitted in
  `citation_publication_date`, RIS, BibTeX or CSL-JSON, which read the parsed parts without
  checking them; `IssuedDate` validates once, for every output, and a span of years typed
  as text (`1999-2001`) is an interval.
- **Page fields are read as locators.** An article number in `bibo:pages` (`e70110`,
  `a04521`) was cut to its digits, so `/s/amira/item/29920` told Google Scholar
  `citation_firstpage 70110` and cited ": 70110"; it is now kept whole, as is a supplement
  range (`S. S204-S207`). A book's extent (`XIV, 310`, `18 S.`) is no longer exported as its
  page range (BibTeX `pages`, RIS `SP`, CSL `page`, `citation_firstpage`): it is the page
  count, exported as biblatex `pagetotal`, CSL `number-of-pages` and, for a `Book`, JSON-LD
  `numberOfPages`, preferring `bibo:numPages` where the record has one.
- **Four citation-style rules that dropped or repeated fields.** A conference paper whose
  proceedings have no publisher named them twice in Chicago and MLA
  (`/s/amira/item/29969`); an article in a journal numbered by issue alone lost its issue in
  APA and lacked Chicago's comma ("*WerkstattGeschichte*, no. 93 (2026)"); MLA put a comma
  before "et al." after a name in natural order ("edited by Olivier Walther et al."); and
  every APA reference ended with a period after its DOI or URL, which APA 7 leaves out. An
  APA article with no journal title no longer opens its source with a stray comma. A test
  now formats every kind in every style with each optional field missing in turn.
- **BibTeX and CSL-JSON fields that reference managers misread.** A book's number in its
  series and a working paper's number went to BibTeX `volume`, which biblatex prints as one
  volume of a multi-volume work (`/s/amira/item/41169`, `/s/amira/item/46451`); they are
  now `number`, as the CSL export already had them. A subject heading that holds a comma
  ("Schramm, Katharina") is braced in BibTeX `keywords` instead of splitting into two
  keywords. Braces in a value are escaped, so a stray `}` can no longer end a field early.
  CSL-JSON `language` is the locale code (`de`) the page head carries, not the label.
- **Series (template 33) and Cluster Chronicles (template 34) are mapped.** Both went live
  unmapped, so their 148 records fell back to a generic `CreativeWork` and a generic
  citation. A series (`bibo:Series`) is now a `BookSeries` naming its publisher, and is
  cited as an entry of the database ("Series record"), like a journal; a Cluster
  Chronicles work is a `CreativeWork` cited with its collection. The Cluster Chronicles item
  set's breadcrumb lands on the *Cluster Chronicles* page, and the template fixture lists
  both templates. A journal's JSON-LD names its publisher too.
- **Omeka's `noindex` for a record outside the site is kept.** Core prepends `noindex` to an
  item, media or item-set page the current site does not hold; the layout pass then wrote
  `max-image-preview:large` with Laminas' `setName()`, which removes every robots meta, so
  the page became indexable under a self-canonical while the sitemap left it out (live on
  the Series and Cluster Chronicles item sets, which are outside AMIRA's item pool). A
  robots directive another writer put in the head now counts as already set, as CLAUDE.md's
  "the first directive stands" always meant; the staging noindex still overrides it.
- **IndexNow is not pinged from a staging site, nor for URLs the sitemap leaves out.** With
  *Discourage indexing* on, a copy of production settings still queued and submitted its
  URLs and served its `/{key}.txt`, while robots.txt said `Disallow: /`; now nothing is
  queued or sent, and the key file answers 404. A save no longer queues a page an editor set
  to noindex, a record the default site does not hold, or a project's item set whose page
  redirects (301) to the project item.
- A record with no image of its own shares the site's default image with the site's title as
  its `og:image:alt` / `twitter:image:alt`, not the record's title, which described the
  banner as the record to anyone reading a link preview with a screen reader.
- **The admin forms keep what an editor typed.** A configuration submission that fails
  validation (a mistyped IndexNow key) is shown again with every other edit and the errors
  beside the fields, where the form reloaded the stored settings and the edits were lost.
  The static-page table carries Omeka's own 12-hour CSRF token instead of a one-hour one
  that turned a long editing session into "Invalid form submission.". A blank *Sitemap
  cache lifetime* is the default (86400), as its placeholder says, where it used to switch
  the cache off; 0 still does.
- Saving the static-page table changes only the pages it listed, so a page the editor
  cannot see keeps its overrides, and only a user who may change the site's settings (its
  owner, a site administrator, a global administrator) can save it; Omeka grants editing a
  site's pages no more widely.
- *Noindex filtered/paginated browse* reads as on when the setting is missing, as the
  module's default and the configuration form already said.
- A value that links to another record is read as that record's title wherever the module
  wants text (a description, a volume, a date). Omeka renders a linked value as the
  record's URL, which a term that ever held a link would have printed into the meta
  description or a citation; the unit suite's stand-in rendered the title, so no test could
  see it.
- **A record's people are read once, for every output.** Two different people who share a
  name ("Müller, Thomas") were one JSON-LD `author`, carrying the second one's `@id`, while
  the citation named both; one person entered twice was one author in the JSON-LD and two in
  the citation. The generated description split an organisation's name like a person's
  ("Report by University of Bayreuth Institute of African Studies"). The citation, the
  Highwire and Dublin Core tags, the JSON-LD and the description now read one list, which
  counts each linked record once by its id and keeps an organisation's name whole.
- **A translation would now load.** The module registers `language/` with Omeka's
  translator (`translation_file_patterns`), as DRE Search and DRE Visualizations do, so a
  `language/<locale>.mo` compiled from a translator's `.po` reaches the pages; until now none
  could. `compile-po.php` keeps the catalogue's own header (it wrote French plural rules
  into every locale), leaves out entries flagged fuzzy, and keeps a `msgctxt` and every
  plural form.
- A verification snippet pasted with single quotes (`content='…'`) is read instead of
  dropping the tag, and a snippet for the other engine (the Bing tag in the Google field) no
  longer emits Bing's token under Google's name.

### Removed

- Listeners Omeka S 4.2.1 never triggers: `view.show.after` on the site item-set and index
  controllers (an item set's page is the item browse; the site root only redirects) and
  `view.browse.after` on the media controller, which has no browse. robots.txt no longer
  names `/s/*/media?`, a route that answers 404.
- Code nothing called: `ResourceValueReader::pageRange()`, `LinkedDataIdentity::page()`, the
  `StructuredData::DATASET_DESCRIPTION_MIN` alias, the unmapped `ResearchOrganization` type,
  an unused citation string and two unused dashboard labels.

## [0.17.0] - 2026-11-11

### Added

- `composer bump-version -- X.Y.Z` (`.github/scripts/bump-version.php`) sets the version in
  `config/module.ini`, `CITATION.cff` and the CHANGELOG heading, and refuses an empty
  Unreleased section; `--check X.Y.Z` compares them, and the release workflow runs it on
  the tag. See [CONTRIBUTING.md](https://github.com/AM-Digital-Research-Environment/DRE-SEO/blob/main/CONTRIBUTING.md#releasing).
- **Admin → SEO lists the resource templates in use that the template maps do not name**,
  with their label, item count and which map lacks them. Such a template's items fall
  back to a generic `CreativeWork` and a generic citation, which is how templates 24–32
  once went live unmapped.
- Tests: the `/cite` controller (a private or unknown item and an unknown format are 404,
  ETag and `If-None-Match` revalidation to 304), both admin screens rendered through
  Laminas' real renderer and form helpers, markup in every field escaped in every citation
  style and kind (the theme prints `dreCitation()`'s `html` raw), and a table of the
  properties each schema.org type must carry, checked for every mapped template.

### Changed

- **One identity-link allowlist with DRE Linked Data.** `sameAs` without DRE Linked Data is
  now built by that module's rules, from a byte-identical copy of its authority table
  (`config/identity-authorities.php`, which `ResourceValueReader` no longer duplicates), so
  a record's `sameAs` is the same set with the module on or off. A test pins the table and
  a shared table of cases by sha256, as DRE Linked Data's tests do. What changes in the
  JSON-LD `sameAs` (and Signposting's ORCID `author` links, which read it) without DRE
  Linked Data:
  - `dre:rdspaceHandle` is no longer `sameAs`. AMIRA's 75 research items with one lose an
    `https://hdl.handle.net/rdspace-ubt/…` IRI that the Handle System does not resolve;
    their DOI, which names the same deposit, stays;
  - only URI values of `dcterms:identifier` count: a literal that spells an authority URL,
    or a bare Wikidata id (`Q42`), is no longer linked. A `https://doi.org/…` or
    `https://hdl.handle.net/…` URI in `dcterms:identifier` is no longer `sameAs` either;
    DOIs come from `bibo:doi`. None of these occurs in AMIRA's data today;
  - every `bibo:doi` value is listed, not only the first, and a DOI containing `#`, `?`,
    `%` or `<>` keeps them percent-encoded in its IRI, as DRE Linked Data writes it;
  - ids are matched case-sensitively, so `…/wiki/q42`, a lower-case ORCID check digit `x`
    or an upper-case ROR id is no longer turned into a different IRI; GND ids must have the
    GND shape. Hosts stay case-insensitive and `www.`, `m.` (Wikidata) and a trailing
    slash stay tolerated; a Library of Congress URI with a trailing slash is now accepted;
  - private values are skipped, so a logged-in editor sees the `sameAs` visitors see.

  With DRE Linked Data active, `sameAs` is its list alone: the module's own DOI IRI is no
  longer appended beside it, where it could differ in encoding. Every identifier URI on
  AMIRA's items today (5,108, checked on 11 November 2026) maps to the same IRI as before.
- **One list each of author, editor and description terms.** The citation, the Highwire and
  Dublin Core tags, the JSON-LD, the meta description and the Signposting links read the
  same ordered lists, where the module had five lists in three orders. What a visitor or a
  harvester sees change, on records where the lists disagreed:
  - a podcast with both `dcterms:creator` and a host (`marcrel:hst`) is credited to its host
    in the JSON-LD `author`, the generated description and the Signposting `author` links
    too, as its citation already was; a video, likewise, to its speakers;
  - a work with only `marcrel:hst` or `marcrel:spk` (no author term) now has those people
    as its citation authors and `DC.creator`, as its JSON-LD already had;
  - the JSON-LD `editor` is always the citation's editors (`bibo:editorList`, then
    `marcrel:edt`), on every type, and `contributor` holds `dcterms:contributor` (then
    `marcrel:sde`). Before, a book's `dcterms:contributor` was its `editor`, and an
    article's editors were `contributor`s;
  - descriptions prefer `bibo:abstract`, then `dcterms:abstract`, then
    `dcterms:description`. A publication's meta description, JSON-LD `description` and
    `DC.description` are now its abstract rather than its EPrints note, and a research
    item's its abstract rather than its setting.
- **Omeka S `^4.2.1`** is the declared requirement (`^4.2.0` before), as for the theme and
  the other DRE modules; 4.2.1 is what CI and the integration suite have always tested.
- The release workflow runs the whole CI suite on the tag, the integration tests against
  Omeka S 4.2.1 included, before it builds the zip; it used to run the unit suite only.
- GitHub Actions are pinned by commit SHA, with the release in a comment.
- CHANGELOG headings read `## [X.Y.Z] - YYYY-MM-DD`, with a hyphen, as in the theme and
  the other modules.

### Fixed

- The `/cite` download links (the record rail's downloads, the Signposting `describedby`
  links) are assembled from the route instead of written out, so an Omeka installed under
  a path keeps the path in them.
- `DC.date` is the stored date in ISO 8601 (`2018-12-07`), as precise as recorded, read
  from the raw value like `datePublished`; it no longer depends on the value's string
  form, which a timestamp stored with a time, or a `rep.value.string` listener, could
  change. A date that names no year is left out instead of being passed through as text.
- A JSON-LD document holding invalid UTF-8 is no longer dropped from the page: the bad
  bytes become U+FFFD. A document that still cannot be encoded is logged.
- The sitemap button on **Admin → SEO** reads **Regenerate**, as documented, instead of
  Omeka's default "Confirm".
- The admin dashboard's "On" and "Off" labels and its explanatory notes meet WCAG AA
  contrast (5.4:1 and 5.7:1 on white, from 4.0:1 and 2.8:1).

## [0.16.0] - 2026-10-29

IndexNow URLs wait in a queue table of their own, sent by a cron job (a bulk sync is
still left to the sitemap), the sitemap follows content changes at once, and robots.txt
keeps crawlers off search and browse variants. **After replacing the module folder, run Admin → Modules →
DRE SEO → Upgrade**, and if IndexNow is on, install the cron line in
[OPERATIONS.md](OPERATIONS.md#the-indexnow-cron).

### Added

- **An IndexNow cron.** `scripts/drain-indexnow.php` sends the queued URLs, 200 per run,
  and is meant to run every five minutes as the PHP-FPM user:
  `*/5 * * * * OMEKA_PATH=/var/www/html /usr/bin/php /var/www/html/modules/DRESeo/scripts/drain-indexnow.php`.
  It refuses to run outside the command line, exits quietly while the module is inactive or
  IndexNow is off, and records an Omeka job only when a URL is due. A save still starts a
  drain job too, at most once every 15 minutes, so a site without the cron keeps pinging,
  only later. `--retry-failed` re-queues the URLs IndexNow refused or that ran out of
  retries, once the cause is fixed.
- **Admin → SEO** counts the IndexNow queue's pending and failed URLs, says how to re-queue
  failed ones, and flags a stored key that is not 8–128 hexadecimal characters (with such a
  key nothing is queued or sent).
- `upgrade()`: Omeka's **Upgrade** now applies every default setting a site does not have
  yet, so a default added after a site's first install reaches it (it never overwrites a
  value an administrator chose), creates the queue table, and migrates the old queue (see
  below). It is safe to run again.
- Sitemaps are sent with `Last-Modified`, the time the document was generated, also when it
  comes from the cache.
- [OPERATIONS.md](OPERATIONS.md): the upgrade, the cron and what to do with failed URLs.

### Changed

- **robots.txt disallows query variants.** Google's guidance on faceted navigation is to
  disallow filter URLs rather than rely on noindex, which a crawler can only read by
  fetching the page. robots.txt now disallows DRE Search's results page with a query
  (`/s/*/dre-search?`) and Omeka's item, item-set and media browse with filters or sorts,
  an item set's landing page included (`/s/*/item?`, `/s/*/item-set?`, `/s/*/item-set/*?`,
  `/s/*/media?`), and allows their clean `?page=` pagination. The pages themselves stay
  `noindex, follow`; landing pages, records, every sitemap URL and DRE Search's
  `/dre-search/api/*` endpoints stay crawlable. The rules are `dre_seo.robots` in
  `config/instance.config.php`. The staging switch still answers `Disallow: /` alone.
- **IndexNow URLs are queued in a table, `dre_seo_ping`, instead of two settings rows.** The
  upgrade moves the URLs still waiting in `dre_seo_ping_pending` into it and deletes
  `dre_seo_ping_pending` and `dre_seo_ping_job_queued`. One row per URL, so concurrent saves
  no longer need a MySQL named lock to keep each other's URLs, and a record saved twice is
  queued once. A job leases its batch for ten minutes, so a worker that dies loses nothing;
  an edit made while its URL was in flight survives the older submission's outcome.
- How IndexNow's answers are acted on: a transient failure (no answer, 408, 429, 5xx) is
  retried by the next drain after a backoff of 2, 4, 8 and 16 minutes, not when the next
  edit happens to come; a refusal (400, 403, 422 or an unfollowed 3xx) is kept and counted
  as failed on the dashboard, not dropped, and the job log names the status and the key
  location.
- **A bulk change is still not pinged**, now measured on the outbox: a drain that finds more
  than 200 URLs waiting for their first submission (`PingQueue::BULK_THRESHOLD`, one
  drain's batch, the old flood cap) removes them unsent and logs the count, leaving their
  discovery to the sitemap, as a batch that reached 200 URLs was skipped before. URLs being
  retried, or re-queued with `--retry-failed`, were sent before and go out whatever their
  number.
- A deleted record's URL is noted before the deletion and queued after it, so a delete that
  fails pings nothing. An update keeps the visibility it had before in a map keyed by its
  own API request, so updates in flight at once (a batch) no longer read each other's.
- **The sitemap follows content changes at once.** Creating, editing or deleting an item,
  item set, site page, media or site clears the cached sitemap, which used to show a change
  only after its lifetime (24 hours by default) or the dashboard's **Regenerate**. The clear
  is a new generation stamp: cheap enough for a bulk import, and a sitemap being rebuilt
  while content changes cannot cache the older state.
- A sitemap that cannot be built — the database fails and no copy of the current generation
  expired less than five minutes ago — is answered `503` with `Retry-After: 300` instead of
  a sitemap that lists nothing, which used to be cached for the whole lifetime. Requests that
  arrive while a sitemap is being rebuilt wait for it (up to five seconds) instead of each
  rebuilding it. Documents are byte-for-byte what they were; `<changefreq>` and `<priority>`,
  when configured, are now escaped like every other value.
- The configuration form refuses an IndexNow key with the message to use 8–128 hexadecimal
  characters, from the same definition the route, the queue and the job use.
- **PHP 8.4 is now the minimum** (`composer.json` `require.php`, was 8.2). Production runs
  8.5, which CI keeps testing alongside the 8.4 floor. The move follows the development
  tools: PHPUnit 13 needs PHP 8.4.1, and `config.platform.php` makes Composer resolve them
  for that floor whichever PHP runs it. The module's code is unchanged; an Omeka
  installation still on PHP 8.2 or 8.3 should stay on 0.15.0.
- `language/template.pot` now lists every translatable string: 78, where the hand-kept
  template had 42 and missed most of the configuration form, the dashboard's notices and the
  Omeka JSON-LD embed warning. It is generated from the sources (`composer i18n`), and CI
  fails when it falls behind them (`composer i18n:check`).
- Release notes on GitHub are the release's CHANGELOG section rather than a generated list
  of commits, with a paragraph on checking the zip against its `DRESeo.zip.sha256`. A release
  whose section is missing is not published.

### Fixed

- **The Signposting `Link` header no longer replaces one another module set.** Laminas
  decides whether a header may repeat by the class registered for its name, and registers
  none for `Link`, so sending ours as a multi-header object was not enough: the module now
  registers the multi-header class for `Link` on the response first. The header is added
  once per page, from the layout listener, rather than by each action listener that ran
  (a template that triggers both `view.show.after` and `view.browse.after` added it
  twice), and it is capped at 2 KB
  (`Signposting::HEADER_BUDGET`): nginx answers 502 when FastCGI response headers overflow
  its default 4 KB buffer, so the links that do not fit are left to the page head.
- A record without a resource template (a media page, as a rule) no longer raises PHP 8.5's
  "Using null as an array offset" deprecation when its schema.org type is looked up. The
  type is unchanged: the default, `CreativeWork`.
- Uninstall removes the sitemap cache (`files/dre-seo-cache/`) and the queue table, and, on
  a module that was deactivated first, the three site settings too: Omeka does not
  autoload an inactive module's classes, and the "Class not found" was swallowed. Install,
  upgrade and uninstall now load the module's classes themselves.

### Development

- The test suite runs on PHPUnit 13: the 171 unit tests of the former dependency-free
  harness, one test method each, and the Omeka smoke script as a 22-test integration
  suite (`composer test:integration`, with `OMEKA_PATH` pointing at Omeka S 4.2.1).
  `composer install` now fetches the development tools; the module still has no runtime
  dependencies.
- PHPStan analyses `Module.php`, `src/` and `tests/` at level 6. The 13 findings that predate
  it are recorded in `phpstan-baseline.neon`, a ledger to shrink, never to regenerate.
- PHP_CodeSniffer checks PSR-12, and `.editorconfig` records the conventions.
- `composer check` runs the translation check, PSR-12, PHPStan and the unit suite. CI splits
  them into a test matrix (PHP 8.4 and 8.5), a quality job at the 8.4 floor and the
  integration job; it runs on pushes to `main` and on pull requests, and cancels superseded
  runs. The release job runs the same gates at the tag, and its leak check covers the new
  tool configurations. Dependabot also proposes major bumps of the development tools.
- Ported from IWAC SEO: the IndexNow outbox (`PingRepository` behind `PingOutboxInterface`,
  `PingOutcome`, `PingQueue` as the dispatch policy), the scoped lifecycle autoloader, and the
  sitemap split into `Sitemap\SitemapRepository`, `UrlsetWriter`, `XmlCache` and
  `SitemapDocument`, with the generator keeping only the policy. Their tests come along:
  the outbox and the drain job against SQLite, the lifecycle on an inactive module, the
  writer, the cache, the listeners, the controller's headers and 503. Characterisation tests
  pin every sitemap document byte for byte.
- Ported from IWAC SEO and restructured, with no change to what the module emits:
  `SettingsGate` reads every `dre_seo_*` setting (the static `SettingFlag` is gone);
  `HeadWriter` does the head writes and keeps the request's record of them, leaving
  `HeadMetadata` the policy; `Module.php` is wiring, its listeners' work moved to
  `Listener\ContentChanges`, `Listener\ProjectItemSetRedirect` and
  `Form\SiteSettingsElements`; `BreadcrumbTrail` builds the trail for both the
  `BreadcrumbList` and `dreBreadcrumb()`, and `CollectionNodes` the Dataset and
  CollectionPage nodes, out of `StructuredData`; AMIRA's `dre_seo` block moved to
  `config/instance.config.php`, kept disjoint from the wiring by a test; and the public
  controllers answer through the `SendsResponses` trait. Characterisation runs over the
  head, JSON-LD, breadcrumb and controller output were byte-identical before and after.
- [CLAUDE.md](CLAUDE.md) records, for coding agents, what bites on the maintainer's machine
  and in this codebase.

## [0.15.0] - 2026-10-15

The research project's item becomes its one public page: its item set redirects there, and
every record is placed in the site through the landing page of its kind.

### Added

- A research project's item-set page (`/s/{site}/item-set/{id}`) answers a permanent
  redirect (301) to the project item in the same site, so search engines and readers land on
  one page per project. An item set stands for a project when its own `dcterms:isPartOf`
  links to a public `foaf:Project` item — the link the sync now writes — or, until then, when
  a public `foaf:Project` item has the same `dre:id`. GET and HEAD requests on that route
  only: `/item?item_set_id=…`, the admin and the API are untouched, and an item set with no
  public project keeps its page. The redirect is issued before the theme renders. Site
  setting **Redirect a project's item set page to the project** (Sites → *site* → Settings →
  SEO), on by default.
- Site setting **Breadcrumb landing pages**: the page that presents each item set's records,
  one `item set id = page slug` per line over the shipped defaults (AMIRA's: Projects →
  *Research projects*, Research Sections → *Research sections*, Persons → *People*,
  Institutions → *Organisations*, Publications, Podcasts, YouTube videos, Subjects →
  *Subjects & tags*, Locations, Languages, Genres; Journals and Series have none). A page
  that does not exist or is private is skipped.
- Item-set pages state their `BreadcrumbList` too, the trail `dreBreadcrumb()` now returns for
  an item set, so DRE-theme can show it there.

### Changed

- The breadcrumb (visible and `BreadcrumbList`) places a research item under its project and
  the project under its landing page: *Home › Research projects › project › record*. Other
  records go under the landing page of their item set (*Home › Publications › record*,
  *Home › People › person*), and fall back to the item set as before when there is none. A
  media's trail is its item's, then the item; an item set's is *Home › landing page › item
  set*. An item set whose page redirects is named by its project, so no crumb is a redirect.
- The item-set sitemap leaves out the item sets that redirect to their project (in the same
  single query), and the admin dashboard counts the item sets it lists.

## [0.14.0] - 2026-10-15

### Added

- `dreBreadcrumb($resource)`, a view helper that returns a record's trail as `[['name' => …, 'url' => …], …]`, for the breadcrumb DRE-theme now shows above a record. It is the trail the `BreadcrumbList` states, so the visible breadcrumb and the structured data agree.

### Changed

- The `BreadcrumbList` places a record in the site: an item under its item set (a research item's project, *Publications*, *Institutions* …), preferring one the site uses; a media under its item. It used to go straight from the home page to the record. Item sets and other records keep the two-step trail.

## [0.13.1] - 2026-10-15

### Changed

- The schema.org `Dataset` is on the dataset's landing page, the page that carries DRE Linked Data's *Linked open data* block, and its `url` is that page. DRE Linked Data 0.3.1 names the page (`dataset()['landingPage']`) and gives the same page as the VoID's `dcat:landingPage`, so Google Dataset Search and data catalogues send people to the page that explains the dataset. With DRE Linked Data 0.3.0, which names no landing page, the `Dataset` stays on the home page. The home page keeps its `WebSite` node.

## [0.13.0] - 2026-10-15

An SEO and FAIR audit of the live site, applied together with DRE Linked Data 0.3.0 and
DRE-theme 2.38.0 under one shared identity contract. Everything that reads DRE Linked Data
falls back to the 0.12 behaviour without it, and works with its 0.2.0 helper.

### Added

- The catalogue as a schema.org `Dataset` on the home page, from DRE Linked Data's
  `dataset()` (0.3.0+): VoID URI as `@id`, a description of at least 50 characters (Google
  Dataset Search's minimum), licence, publisher with its Wikidata `sameAs`, `dateModified`,
  the dumps as `DataDownload`s and AMIRA as the `DataCatalog`.
- Research items (an item whose `dcterms:isPartOf` links to a `foaf:Project` record — the
  rule DRE-theme's `RecordAccess` applies) state `conditionsOfAccess`: their access rights
  and a statement that AMIRA holds the metadata only and access requests go to the project
  (setting **Access statement for research items**, translatable by default).
  `isAccessibleForFree` is set only when the access rights decide it.
- `/cite/{id}/txt`: the default style's citation as UTF-8 plain text, offered by
  `dreCitation()` as "Plain text"
  ([#1](https://github.com/AM-Digital-Research-Environment/DRE-SEO/issues/1)). Every `/cite`
  download carries `Access-Control-Allow-Origin: *`, an `ETag`, `Last-Modified` and
  `Cache-Control: public, max-age=300`, and answers a conditional request with 304.
- Signposting `author` links (ORCID iD, else the person's resource URI); item-set landing
  pages and media pages are signposted too, a media page with `collection` pointing at its
  item.
- An item set's `CollectionPage` has an `@id` and a `mainEntity` `Collection` under the
  item set's resource URI.
- `og:image:width` / `og:image:height` when the share image is a local file.
- The per-page **JSON-LD** override (Admin → SEO → Static pages), stored since 0.1 but never
  read, now switches a page's JSON-LD off.

### Fixed

- The home page's canonical was `/` and the sitemap listed `/`, but `/` and `/s/amira` only
  redirect to `/s/amira/page/home`; the `WebSite` url, the breadcrumb root and
  `CollectionPage.isPartOf` named `/s/amira/` — with the slash, a 404. The homepage page's
  own URL is now the one home URL everywhere (canonical, `og:url`, sitemap, IndexNow,
  JSON-LD), and its `og:title` / `og:image:alt` are the site title instead of "Home".
- IndexNow never queued anything: the `api.*.post` events carry the entity, which has no
  `siteUrl()`, so no URL was ever built. The representation is now built from the adapter.
  Item sets are pinged too, and so is a record just made private or about to be deleted.
  The queue's read-modify-write runs under a MySQL named lock and bypasses Omeka's
  per-request settings cache, so concurrent saves no longer drop URLs; a permanent IndexNow
  refusal (400/403/422) is dropped rather than requeued.
- Highwire `citation_*` tags only on scholarly papers — an article, conference paper,
  chapter, book, thesis or report that names an author. A research-item video with no author
  (/s/amira/item/8698) carried `citation_title` and a date, as did every podcast, video,
  dataset, post and journal issue; they now carry Dublin Core only, `DC.creator` naming a
  podcast's host or a video's speakers.
- A linked record in the JSON-LD is typed as its own page types it: a research item's
  project is a `ResearchProject`, not a `CreativeWork`.
- `sameAs` is limited to authority IRIs in canonical form (DRE Linked Data's list when it
  has one); YouTube playlists and GEPRIS pages are no longer `sameAs`, and Wikidata is
  `http://www.wikidata.org/entity/Q…` as in the RDF.
- A linked licence item ("CC-BY-NC-SA-4.0") resolves to its creativecommons.org URL in the
  JSON-LD and in Signposting `license`; before, the JSON-LD gave its label as text and
  Signposting skipped it.
- `datePublished`, `dateCreated` and `uploadDate` are ISO 8601; a video without a
  publication date takes its `dcterms:created` (else the record's creation) as
  `uploadDate`.
- `inLanguage`, `citation_language` and `DC.language` are BCP 47 tags (`de`, `pcm`) from the
  language record's ISO 639 code, not labels ("German").
- Search results pages (core's site search and advanced search, DRE Search's results page)
  and query-string variants of any other page are `noindex, follow` with the browse switch
  on; a record paging through its connection lists stays indexable. Canonicals drop
  tracking parameters, and a page nothing else described is canonical without its query.
- The pages sitemap leaves out pages set to noindex, and no sitemap is served (or named in
  robots.txt) while the whole site is noindex.

### Changed

- **Identity** (shared contract with DRE Linked Data and DRE-theme): Signposting `cite-as`
  (head and `Link` header), `DC.identifier` and the URL in formatted citations and
  BibTeX/RIS/CSL-JSON downloads are the record's DRE Linked Data URI, else its page. The
  DOI leaves `cite-as`; it is a JSON-LD `PropertyValue` `identifier` and a `sameAs`.
  Canonical, `og:url` and JSON-LD `url` stay the page.
- Inline JSON-LD is compact (no pretty-printing).
- `LinkedDataIdentity` describes each resource once per request, and `CitationData` builds
  each item's record once per request across representations.
- The lastmod timezone was checked against Omeka (naive UTC datetimes) and is correct; the
  reason is now documented.

## [0.12.0] - 2026-10-14

### Added

- One identity per record with [DRE Linked Data](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData)
  ([#2](https://github.com/AM-Digital-Research-Environment/DRE-SEO/issues/2)). When that
  module is installed, the schema.org JSON-LD gives the record its permanent
  `/resource/{id}` URI as `@id`, and `url` stays the record page. Every linked record it
  names (authors, editors, containers, affiliations, funders, members, places) gets its own
  resource URI as `@id` too. Subject headings that are records are listed under `about` as
  `DefinedTerm` nodes; `keywords` is unchanged. The lookup is guarded on the
  `dreLinkedData` helper, so without the module nothing changes.
- Signposting `describedby` points at DRE Linked Data's Turtle, N-Triples and JSON-LD
  documents instead of Omeka's `/api/items/{id}`, when the module is installed. The
  CSL-JSON, BibTeX and RIS entries stay.
- The Signposting set (`cite-as`, `describedby`, `type`, `license`, `item`) is also sent as
  an HTTP `Link` header on item pages (FAIR Signposting level 1). It is sent as a
  multi-header, so another module's `Link` header is kept.
- The configuration page warns while Omeka's own JSON-LD embed is on for the site. That
  block repeats the record under its API URL, with every value (a thesis's full text
  too), and contradicts the schema.org block. Public pages read the site setting
  (**Sites → site → Settings → Disable JSON-LD embed**), not the global one.

## [0.11.0] - 2026-10-09

### Added

- Authority records are cited as entries of the database. A person, place, organisation,
  project, research section, subject heading or journal has no author and is not a work,
  but it can be cited as what it is: `“Aas, Norbert.” Person record. Africa Multiple
  Interactive Research Atlas (AMIRA). Africa Multiple Cluster of Excellence, University of
  Bayreuth. Last modified June 9, 2026. <permalink>.` — in Chicago, APA and MLA, with
  BibTeX `@online`, RIS `ELEC` and CSL `webpage` downloads (the types Zotero reads back as
  a web page). The last-modified date stands in for an access date. They still carry no
  Highwire tags, so Google Scholar never indexes them as articles. The publisher named is
  `dre_seo.citation.publisher`.
- `dreCitation()` returns `citeAs` (`'work'` or `'entry'`) beside `citable`, which keeps
  its meaning ("is a work"). The citation record's array form gains `place`, `number`,
  `event`, `platform`, `genre`, `archive`, `updated`, `authorRole` and `isEntry`.
  Additive; no existing key changed.
- FAIR Signposting (level 1) `<link>` elements on item pages: `cite-as` (the DOI, else the
  permalink), `describedby` for the CSL-JSON, BibTeX, RIS and Omeka JSON-LD descriptions,
  `type`, `license` (when it is a URI) and `item` for the public files.
- Item-set landing pages are described by their item set: title, description, image and a
  `CollectionPage` in JSON-LD. They had no description at all.
- Records without a description get one built from their metadata ("Book chapter by Ute
  Fendler, Marie-Anne Kohl et al. (2024), in Of Worlds and Artworks.") instead of a
  boilerplate that repeated the title. The meta description, `og:description` and JSON-LD
  share it (`ResourceSummary`).

### Fixed

- Citations for every document type, checked against the live records:
  - a thesis names its awarding institution, read from `marcrel:dgg`; none did;
  - ISBNs are read from `bibo:isbn13`, where ERef records keep them; none were cited;
  - a page range written into `bibo:pages` ("S. 23-38") is read;
  - ISBD's spaced colon ("Title : Subtitle") becomes a colon;
  - a quoted title that ends in punctuation no longer gets a second period, and its own
    double quotes nest as single ones;
  - a conference paper without proceedings is cited as presented ("Paper presented at
    …", APA "[Paper presentation]"), not "In" its own title;
  - APA attaches the descriptor to the title (`*Title* [Data set].`) and names a thesis
    "[Doctoral dissertation, Institution]"; MLA italicises a thesis title and ends
    "Institution, PhD dissertation", closes the last container with the URL after a comma
    and abbreviates months;
  - a newspaper or other unnumbered periodical article carries its full date;
  - a YouTube video is cited on its platform ("YouTube video, July 8, 2022");
  - a podcast credits its host as host and numbers the episode ("(No. 1) [Audio podcast
    episode]");
  - a research item is cited as an archival object — its project and the archive — rather
    than with its project as publisher; a book or report in a series names it (Chicago);
  - page ranges take an en dash.
- `og:locale` was always `en`: the locale lookup used `method_exists()` on a view helper,
  which never finds one. It now reads the page language (`en_US`, `fr_FR`, …).
- `og:type` is `profile` for a person, `book` for a book, `video.other` for a video and
  `website` for the other descriptive records, item sets and media, instead of `article`
  everywhere.
- JSON-LD no longer claims the site's default share image, or Omeka's generic audio and
  file icons, as a record's image. Link previews still fall back to the share image.
- Subject headings and other vocabulary terms (template 6) are `DefinedTerm`s in their
  vocabulary, journals (23) `Periodical`s and research sections (7) `ResearchProject`s with
  members; they were `CreativeWork` and `Collection`.
- Media pages are `noindex, follow` and titled by their item when their own title is a
  file URL. They emit no Highwire tags, and neither do item-set pages.
- The sitemap index dates each child sitemap by the latest change among its URLs, not by
  the time it was generated.

### Changed

- JSON-LD: persons are named in natural order with `givenName`, `familyName` and the
  record's form as `alternateName`; authority URIs (GND, Wikidata, LCSH) become `sameAs`;
  places carry numeric coordinates and `containedInPlace`; projects `funder`,
  `parentOrganization` and `alternateName`; works their DOI as `identifier`,
  `dateModified`, page numbers, `funder`, a typed container (`PublicationIssue` →
  `PublicationVolume` → `Periodical`, a chapter's `Book`, a podcast's `PodcastSeries`),
  creators linked to an organisation as `Organization`; videos `embedUrl` and `duration`;
  datasets `distribution` and `includedInDataCatalog`.
- The home page's `WebSite` drops the `SearchAction`; Google retired the sitelinks search
  box in November 2024.
- The shipped sitemap config emits no `<priority>` or `<changefreq>`; Google and Bing both
  document that they ignore them. They can still be set in `config/local.config.php`.
- Indexable pages carry `robots: max-image-preview:large`, and link previews
  `og:image:alt`.
- The Highwire tags are written from the same citation record as the formatted citations
  and downloads. `DC.type` names the exact Zotero item type for theses, reports, books,
  chapters, conference papers, blog posts, datasets, podcasts and videos. A podcast's
  `citation_author` is its host only, as on the record page; guests were listed as authors.
- `CitationMeta` and `StructuredData` read through the shared `ResourceValueReader`
  instead of private copies, and the four copies of the default-site lookup are one
  `DefaultSite` service.

## [0.10.1] - 2026-10-07

### Removed

- JSON-LD `sameAs` no longer includes `dre:wisskiUrl`, which persons and research items
  carried. WissKI was retired on 2026-10-07, and the property is being cleared from every
  AMIRA item. The RDSpace handle (`dre:rdspaceHandle`) still feeds `sameAs`, and a video's
  watch URL still joins it.

## [0.10.0] - 2026-10-06

### Fixed

- The publication templates 24–32 (preprint, newspaper article, legal commentary,
  encyclopedia entry, translation, series editorship, habilitation, master's and bachelor's
  thesis) were in neither template map, so their items were typed `CreativeWork` and cited
  as generic research items. Both maps now name them: a newspaper article is a
  `NewsArticle` cited as an article, encyclopedia entries and legal commentaries are
  chapters of their volume, a series editorship is a `CreativeWorkSeries` cited as an
  edited book, a preprint is cited like a working paper, and the three theses are
  `Thesis`.
- A master's or bachelor's thesis is no longer cited as a "PhD diss." The thesis
  templates share one kind, so the degree is now read from the item's FaBiO class
  (`fabio:DoctoralThesis`, `fabio:MastersThesis`, `fabio:BachelorsThesis`) and drives the
  Chicago/APA/MLA label and the BibTeX entry type (`@mastersthesis`, with a `type` field
  for a bachelor's thesis). A thesis whose class names no degree — the habilitation's
  plain `fabio:Thesis` — reads as "Thesis" rather than claiming a doctorate.

### Added

- `degree` in the citation record's theme-facing array (`'doctoral'`, `'masters'`,
  `'bachelors'` or `null`). Additive; no existing key changed.
- `tests/amira-templates.php`, the instance's template list that the configuration tests
  check both maps against. The 0.9.0 test asserted a fixed list of ids, which the nine new
  templates were never added to.
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

## [0.9.0] - 2026-08-04

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

## [0.8.0] - 2026-08-04

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

## [0.7.0] - 2026-08-01

### Added

- An Omeka S integration smoke test that builds the module's real service factories,
  shared event listeners, configuration form and DBAL-backed sitemap generation against a
  checksum-pinned official Omeka S release.
- GitHub Actions CI on PHP 8.2 and the production PHP 8.5 runtime.

### Changed

- IndexNow pings coalesce through a `PingQueue`: concurrent edits become one background
  batch, and failed submissions are retained for a later retry.

## [0.6.0] - 2026-07-31

### Added

- A dependency-free unit harness (`composer check`) covering sitemap XML generation and
  caching, static-page override normalisation, the template mapping, `VideoObject` output
  and IndexNow key validation.

### Changed

- SEO services refactored; `PageSeoStore` normalises per-page overrides.

## [0.5.0] - 2026-06-25

### Fixed

- The home page declares the bare domain as its canonical instead of
  `/s/{slug}/page/{slug}`, matching the URL Google actually consolidates on and clearing
  the "duplicate, Google chose a different canonical" status. The pages sitemap lists it
  there too.
- JSON-LD is emitted as raw JSON via `noescape`, dropping the `//<!-- … //-->` comment
  guard Laminas `HeadScript` wraps inline script bodies in. `setAutoEscape()` governs only
  attribute and type escaping, so the previous call never removed it. `JSON_HEX_TAG`
  already escapes `</script>`.

## [0.4.0] - 2026-06-12

### Fixed

- Search Console structured-data errors. JSON-LD always emits a `description` (typed
  boilerplate fallback), fixing the critical "missing description" error on `Dataset`
  items; datasets expose authors as `creator`, where Google reads them.
- Book reviews (template 18) are typed `ScholarlyArticle` rather than `Review`. Scholarly
  reviews carry no `reviewRating`, so Google rejected the `Review` type for "missing
  itemReviewed". They keep full journal and article citation metadata.

### Added

- `license` emitted from `dcterms:license` when present; no default is asserted.

## [0.3.0] - 2026-06-05

### Added

- Podcast resource type (template 21) mapped to schema.org `PodcastEpisode` and a
  `podcast` citation kind. Zotero has no Highwire container tag for podcasts, so item-type
  detection rides on `DC.type=podcast`; host and guests ride `citation_author`, which
  Zotero folds onto the primary podcaster role.

## [0.2.0] - 2026-06-04

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

## [0.1.0] - 2026-06-03

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

[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.18.0...HEAD
[0.18.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.13.1...v0.14.0
[0.13.1]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.13.0...v0.13.1
[0.13.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.10.1...v0.11.0
[0.10.1]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.10.0...v0.10.1
[0.10.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/releases/tag/v0.8.0
[0.7.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/6dacce1
[0.6.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/5055f66
[0.5.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/3712426
[0.4.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/5a5f2bd
[0.3.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/9a03152
[0.2.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/a08e159
[0.1.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/commit/58b9a6a
