# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions before 0.8.0 were tagged retroactively from the commit history; they had no
GitHub release and shipped no installable asset.

## [Unreleased]

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

## [0.14.0] — 2026-10-15

### Added

- `dreBreadcrumb($resource)`, a view helper that returns a record's trail as `[['name' => …, 'url' => …], …]`, for the breadcrumb DRE-theme now shows above a record. It is the trail the `BreadcrumbList` states, so the visible breadcrumb and the structured data agree.

### Changed

- The `BreadcrumbList` places a record in the site: an item under its item set (a research item's project, *Publications*, *Institutions* …), preferring one the site uses; a media under its item. It used to go straight from the home page to the record. Item sets and other records keep the two-step trail.

## [0.13.1] — 2026-10-15

### Changed

- The schema.org `Dataset` is on the dataset's landing page, the page that carries DRE Linked Data's *Linked open data* block, and its `url` is that page. DRE Linked Data 0.3.1 names the page (`dataset()['landingPage']`) and gives the same page as the VoID's `dcat:landingPage`, so Google Dataset Search and data catalogues send people to the page that explains the dataset. With DRE Linked Data 0.3.0, which names no landing page, the `Dataset` stays on the home page. The home page keeps its `WebSite` node.

## [0.13.0] — 2026-10-15

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

## [0.12.0] — 2026-10-14

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

## [0.11.0] — 2026-10-09

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

## [0.10.1] — 2026-10-07

### Removed

- JSON-LD `sameAs` no longer includes `dre:wisskiUrl`, which persons and research items
  carried. WissKI was retired on 2026-10-07, and the property is being cleared from every
  AMIRA item. The RDSpace handle (`dre:rdspaceHandle`) still feeds `sameAs`, and a video's
  watch URL still joins it.

## [0.10.0] — 2026-10-06

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

[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v0.14.0...HEAD
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
