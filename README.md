# DRE SEO

[![CI](https://github.com/AM-Digital-Research-Environment/DRE-SEO/actions/workflows/ci.yml/badge.svg)](https://github.com/AM-Digital-Research-Environment/DRE-SEO/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/AM-Digital-Research-Environment/DRE-SEO?sort=semver&label=release)](https://github.com/AM-Digital-Research-Environment/DRE-SEO/releases/latest)
[![Omeka S ^4.2.0](https://img.shields.io/badge/Omeka%20S-%5E4.2.0-3b5f7d)](https://omeka.org/s/)
[![PHP >= 8.2](https://img.shields.io/badge/PHP-%E2%89%A5%208.2-777bb4)](https://www.php.net/)
[![Licence: GPL-3.0-or-later](https://img.shields.io/badge/licence-GPL--3.0--or--later-009260)](LICENSE)

Search-engine optimisation and XML sitemap for the **Africa Multiple DRE** Omeka S
instance ([data.africamultiple.uni-bayreuth.de](https://data.africamultiple.uni-bayreuth.de)).

The site ships almost no SEO metadata out of the box: pages have a `<title>` but no
description, no Open Graph / Twitter cards, no canonical link, no structured data, no
Google Search Console verification, and there is no `sitemap.xml` or `robots.txt`. This
module fills all of that in — **manually for static pages, automatically for every
resource page** — and adds a sitemap, a robots file, and an optional IndexNow ping.

It is a self-contained, settings-only module in the DRE family (alongside `DRESearch`,
`ResourceVisualizations`, `DRE-theme`). No third-party Composer dependencies, no database
tables, no theme edits.

---

## What it does

| Area | Detail |
|---|---|
| **Meta tags** | `<title>`, `<meta name="description">`, canonical link on every public page. |
| **Open Graph / Twitter** | `og:title/description/image/type/url/site_name/locale` + `twitter:card/title/description/image/site` so shared links render a rich preview. |
| **schema.org JSON-LD** | Per-resource structured data typed from the resource template (Person, Place, Organization, ResearchProject, DefinedTerm, Periodical, CreativeWork, Dataset, scholarly types …), with allowlisted authority IRIs as `sameAs`; every linked record typed as its own page types it. `WebSite` on the home page, the catalogue as a `Dataset` on its landing page (the *Linked open data* page), `CollectionPage` on item-set pages and `BreadcrumbList` on resource pages (home › item set › item, or home › item › media; the theme shows the same trail through the `dreBreadcrumb()` view helper). With [DRE Linked Data](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData), every record node takes its permanent `/resource/{id}` URI as `@id`. |
| **Citations** | "Cite this record" on every record page — Chicago, APA, MLA and BibTeX/RIS/CSL-JSON/plain-text downloads for works, and an entry citation ("Person record. AMIRA. Last modified …") for authority records. |
| **FAIR Signposting** | `cite-as`, `describedby`, `type`, `author`, `license`, `item` and `collection` links on item, item-set and media pages, as `<link>` elements and as an HTTP `Link` header, for FAIR assessors such as F-UJI. |
| **Citation metadata (Zotero)** | Highwire Press `citation_*` tags for scholarly papers (articles, chapters, books, theses, reports … with an author) and Dublin Core `DC.*` for every record, so the **Zotero Connector**, Google Scholar, Mendeley and other reference managers capture each record as a properly-typed reference, and Scholar indexes only papers. |
| **og:image** | The first image attached to an item; falls back to a site-wide default share image. Its size is declared when it is a local file. |
| **XML sitemap** | `/sitemap.xml` index → `/sitemap-pages.xml`, `/sitemap-item-sets.xml`, `/sitemap-items-{n}.xml` (chunked at 50k). Public resources only, each with its real `<lastmod>`; the index dates each child by its latest change; pages set to noindex are left out. No `<changefreq>`/`<priority>` by default — Google and Bing ignore them. Cached. |
| **robots.txt** | `/robots.txt` disallowing `/admin` and pointing crawlers at the sitemap. A staging switch can `Disallow: /` the whole site (and then serves no sitemap). |
| **Google Search Console** | Paste the verification snippet in the module config; the `<meta name="google-site-verification">` tag is injected site-wide. |
| **IndexNow ping** | Optionally notifies Bing/Yandex when public items, item sets or pages are created, edited, made private or deleted. Concurrent edits coalesce into one background batch under a database lock; transient failures are retained for a later retry. |

Static-page SEO is **set by hand** (a central admin table); resource-page SEO is **derived
automatically** from each item's metadata, with the configurable defaults filling any gaps.

---

## Installation

Download `DRESeo.zip` from the [latest release](https://github.com/AM-Digital-Research-Environment/DRE-SEO/releases/latest)
and unpack it into Omeka's `modules/` directory. The archive already unpacks to a folder named
`DRESeo`, which is required — the folder name has to match the namespace, exactly like
`DRESearch`. Each release also carries a `.sha256` file if you want to verify the download.

For the DRE instances, deploy it the same way as the other DRE modules (the `omeka-s-docker`
sideload / `EXTRA_MODULES` mechanism or the ansible-bootstrap copy). Either way, then:

1. **Admin → Modules → DRE SEO → Install.**
2. **Configure** (see below) — at minimum paste your Google Search Console snippet and pick a
   default share image.
3. Visit `/sitemap.xml` and `/robots.txt` to confirm they render.

No web-server changes are needed: nginx's `try_files … /index.php` already routes the
`.xml`/`.txt` endpoints to Omeka because no such static files exist.

There are no third-party dependencies, so `composer install` is **not** required — Omeka
autoloads the `DRESeo\` namespace from `src/`.

**Requirements:** Omeka S `^4.2.0`, PHP `>= 8.2`. CI covers both the supported
floor (PHP 8.2) and the production runtime (PHP 8.5).

---

## Configuration

**Modules → DRE SEO → Configure.** Every field is optional; sensible defaults are applied on
install.

| Setting | Purpose |
|---|---|
| **Google Search Console verification** | Paste the whole `<meta name="google-site-verification" …>` tag (or just the token). Injected on every public page. Then add the property at [search.google.com/search-console](https://search.google.com/search-console) and submit `/sitemap.xml`. |
| **Bing Webmaster verification** | Same, for the `msvalidate.01` tag. |
| **Default meta description** | Used on pages without their own description (home, browse, search). |
| **Default social share image** | `og:image` fallback (≈1200×630). Used when a page or an item with no media is shared. |
| **Twitter / X @handle** | Emitted as `twitter:site`. |
| **Discourage indexing (whole site)** | Staging switch: every page becomes `noindex,nofollow` and robots.txt disallows everything. **Off in production.** |
| **Noindex filtered/paginated browse** | `noindex, follow` on facet/pagination URLs, search results pages (core's `/s/{slug}/index/search` and advanced search, DRE Search's `/s/{slug}/dre-search`) and any other page URL with a query string, while crawlers still follow links to the resources. A record paging through its connection lists (`page`, `lr_page`, `lr_property`) stays indexable; tracking parameters (`utm_*`, `fbclid`, `gclid` …) never count and are dropped from canonicals. On by default. |
| **Emit schema.org JSON-LD** | Toggle structured data. On by default. While it is on, the configuration page warns if Omeka's own JSON-LD embed is still enabled for the site (see [One identity per record](#one-identity-per-record)). |
| **Access statement for research items** | Closes every research item's `conditionsOfAccess` (see [Research items and access](#research-items-and-access)). Blank: the default sentence, translated into the page language. |
| **Serve /sitemap.xml & /robots.txt** | Toggle the sitemap/robots endpoints. On by default. |
| **Sitemap cache lifetime** | Seconds before the cached sitemap is rebuilt. Default 86400 (24h). |
| **Ping IndexNow when content changes** | Off by default. See the caveat below. |
| **IndexNow key** | A hex key you choose (`openssl rand -hex 16`); served at `/{key}.txt`. |

### Per-page SEO

**Admin → SEO → Static pages** lists every site page with editable **meta title**, **meta
description**, **share image** (asset ID), **indexing** (default / index / noindex) and
**JSON-LD** (on / off). Blank fields fall back to the page's own title and the site-wide
defaults. A page set to noindex leaves the pages sitemap at once. Resource pages are not
listed — their SEO is automatic.

### Admin dashboard

**Admin → SEO** shows what is configured, the sitemap/robots URLs with public-resource counts,
and a **Regenerate** button that clears the sitemap cache so it rebuilds on the next request.

---

## How the metadata is produced

The head signals are written into Omeka's request-global head placeholder helpers
(`headTitle`, `headMeta`, `headLink`, `headScript`), which the theme already echoes in
`<head>` — so **no theme template needs to change**.

* **Resource pages** (`view.show.after` on the Item/ItemSet/Media controllers): title from
  `displayTitle()`, description from `dcterms:description` / `dcterms:abstract` (truncated to
  ~160 chars), canonical from the resource's site URL, `og:image` from the primary media, and
  JSON-LD from the resource template.
* **Static pages** (`view.show.after` on the Page controller): the editor's per-page
  overrides, else defaults; on the home page, `WebSite` JSON-LD and the
  site title as `og:title`. Every page is **self-canonical, the home page included**:
  Omeka renders no page at the host root or the site root — `/` and `/s/{slug}` only
  redirect (302) to the homepage page, `/s/{slug}/page/{slug}` — so a canonical there
  would name a redirect chain. That page URL (the configured homepage, else the first
  page in the navigation, as core redirects) is the one home URL: canonical, `og:url`,
  sitemap `<loc>`, IndexNow, `WebSite.url`, the breadcrumb root and every `isPartOf`
  that names the site.
* **Browse/search pages** (`view.browse.after`): self-referential canonical (less tracking
  parameters) and optional `noindex`. Search results pages and query-string variants of
  any other page are `noindex, follow` too, and a page nothing else described gets a
  canonical without its query string.
* **Every page** (`view.layout`): site-wide constants (`og:site_name`, `og:locale`,
  `twitter:card`, verification tags) and gap-fills for anything not already set. Resource
  values always win because the resource listeners run before the layout listener.

### One identity per record

[DRE Linked Data](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData) gives
every public resource a permanent URI, `https://HOST/resource/{id}`, that content-negotiates
to RDF (and 303-redirects a browser to the page). DRE SEO, DRE Linked Data and DRE-theme
share one identity contract:

| Role | With DRE Linked Data | Without |
|---|---|---|
| `rel=canonical`, `og:url`, sitemap `<loc>`, JSON-LD `url` | the record page `/s/{slug}/item/{id}` | the same |
| **Persistent identifier**: Signposting `cite-as` (head and HTTP `Link`), `DC.identifier`, the URL in the formatted citations and in BibTeX `url` / RIS `UR` / CSL `URL` | `/resource/{id}` | the page |
| JSON-LD `@id` (the record and every linked node) | `/resource/{id}` | none |
| DOI | JSON-LD `identifier` (`PropertyValue`, `propertyID` DOI) and `sameAs` `https://doi.org/…`; never `cite-as`, which would name the publisher's version rather than this record | the same |

* **schema.org JSON-LD.** Linked records it names (authors, editors, containers,
  affiliations, funders, members, places, subjects) carry their own resource URIs as `@id`,
  and subject headings that are records of their own are listed under `about`. Each linked
  node is typed as its own page types it — a research item's project is a
  `ResearchProject`, not a `CreativeWork`. `keywords` keeps every subject as text.
  `sameAs` is DRE Linked Data's list of canonical identity IRIs (0.3.0+); without it, the
  record's own `dcterms:identifier` URIs filtered to an allowlist (Wikidata as
  `http://www.wikidata.org/entity/Q…`, GND, ORCID, VIAF, ISNI, ROR, LoC, GeoNames, DOI, and
  the RDSpace handle) — never a YouTube playlist or a GEPRIS page.
* **Signposting.** `describedby` points at the module's Turtle, N-Triples and JSON-LD
  documents instead of Omeka's `/api/items/{id}`. The CSL-JSON, BibTeX and RIS entries stay
  on item pages. `author` links each linked author by ORCID iD, else by resource URI.
  Item-set landing pages and media pages are signposted too; a media page adds
  `collection`, pointing at its item.
* **HTTP `Link` header.** The same Signposting set is sent as a `Link` header too, per FAIR
  Signposting level 1, because many harvesters read headers only. It is sent with or
  without DRE Linked Data.
* **Dataset.** When DRE Linked Data describes its dataset (`dreLinkedData()->dataset()`,
  0.3.0+), its landing page carries it as a schema.org `Dataset` for Google Dataset Search.
  The landing page is the page with the *Linked open data* block, which DRE Linked Data
  0.3.1+ names as `landingPage` (it is also the VoID's `dcat:landingPage` and the Dataset's
  `url`); with 0.3.0 it is the home page. The node has `@id` the VoID dataset URI, a description of at least 50 characters, the licence, the
  publisher (with its Wikidata IRI as `sameAs`), `dateModified`, the dumps as
  `DataDownload`s and AMIRA as the `DataCatalog`.

The lookup goes through the `dreLinkedData` view helper, guarded on its existence, like the
theme's `dreCitation`, and every key added in DRE Linked Data 0.3.0 (`page`, `sameAs`,
`dataset()`) is read only when present, so 0.2.0 keeps working. Without DRE Linked Data, or
for a record it does not publish, the identities fall back as the table says, and
`describedby` keeps the Omeka API URL.

**Turn off Omeka's own JSON-LD embed.** Omeka core prints a second JSON-LD block into every
public resource page (and one per resource on browse pages). It is the API representation,
identified by `/api/items/{id}`, with every value of the record, including a thesis's full
text. It duplicates and contradicts the schema.org block. On a thesis page it is about 95% of
the HTML. Public pages read the **site** setting: **Admin → Sites → (site) → Settings →
General → Disable JSON-LD embed**. The global setting of the same name under **Admin →
Settings** governs the admin pages only. The module's configuration page warns while the
site setting is off.

### schema.org `@type` map

Driven by `config/module.config.php → dre_seo.structured_data.template_types` (overridable via
`config/local.config.php`), keyed by Omeka resource-template id:

| Template | `@type` |
|---|---|
| Organisation (2) | `Organization` (+ `sameAs` ← Wikidata) |
| Location (3) | `Place` (+ numeric `geo`, `containedInPlace`, `sameAs` ← Wikidata) |
| Persons (4) | `Person` (natural-order `name`, `givenName`/`familyName`, `affiliation`, `sameAs` ← GND and `dre:rdspaceHandle`) |
| Projects (5) | `ResearchProject` (+ `funder`, `parentOrganization`, `alternateName`) |
| Authority Resource (6) | `DefinedTerm` in its vocabulary (`inDefinedTermSet` ← `dcterms:type`, `sameAs` ← LCSH / Wikidata) |
| Research sections (7) | `ResearchProject` (+ `member`) |
| Journals (23) | `Periodical` (+ `issn`) |
| Research items (10) | `CreativeWork` (+ `conditionsOfAccess`, `isAccessibleForFree` — see [Research items and access](#research-items-and-access)) |
| Publications (11–20, 24–32) | `ScholarlyArticle` / `NewsArticle` / `Book` / `Chapter` / `Thesis` / `Dataset` / `BlogPosting` / `CreativeWorkSeries` … (book reviews are `ScholarlyArticle`, not `Review`, which Google requires to carry a `reviewRating`; encyclopedia entries and legal commentaries are `Chapter`, since schema.org has no entry type; a series editorship is a `CreativeWorkSeries`, as it may edit a book series or a journal) |
| Podcasts (21) | `PodcastEpisode` (host/guest ← `marcrel:hst` / `:spk`) |
| YouTube videos (22) | `VideoObject` (`thumbnailUrl` from the ingested media, `uploadDate` from `dcterms:date`, speaker ← `marcrel:spk`, canonical watch URL ← `fabio:hasURL`) |

Creative works also carry `author`/`contributor` (from `bibo:authorList`, `dcterms:creator`,
`marcrel:*`), `datePublished`/`dateCreated` (ISO 8601, as precise as stored; a video's
`uploadDate` falls back to `dcterms:created`, then the record's creation), `inLanguage` (a
BCP 47 tag from the language record's ISO 639 code in `dcterms:alternative`: `deu` → `de`,
`pcm` stays `pcm`), `keywords`, `spatialCoverage`, `isPartOf`, `publisher`, the DOI as
`identifier`, and `license` (← `dcterms:license`) when present; a `Dataset` exposes its
authors as `creator` rather than `author`. A linked licence item resolves to a URL — a
creativecommons.org, rightsstatements.org or opensource.org URL among its identifiers, else
the canonical Creative Commons URL of a well-known label (`CC-BY-NC-SA-4.0`, `CC BY 3.0`,
`CC0-1.0`, or the long "Creative Commons Attribution-…" form) — and is otherwise named as a
`CreativeWork` under its resource URI; Signposting links it only when it has a URL.
`description` is always emitted — it falls back to a typed boilerplate when the item has no
`dcterms:description` / `abstract`.

### Research items and access

AMIRA describes research material it does not hold. An item is a **research item** when one
of its `dcterms:isPartOf` values links to a record of class `foaf:Project` — by resource
class, not template id. This is a rule shared with DRE-theme's `RecordAccess` helper, which
uses it for the record page's access notice, so the page and the structured data agree on
which records it applies to. A research item's JSON-LD carries:

* `conditionsOfAccess`: its access rights (`dcterms:accessRights` labels: "Public", "Group",
  "Individual", "No Raw Data") followed by a statement that AMIRA holds the metadata only and
  access requests go to the project (setting **Access statement for research items**; by
  default a translatable sentence);
* `isAccessibleForFree`, only when every access right recorded decides it
  (`dre_seo.structured_data.access_free`: Public → true, Group and Individual → false);
  "No Raw Data" decides nothing, so the property is left out.

`citation_pdf_url` and a Dataset's `distribution` already name public media files only, so
an access right does not suppress them: a file that is attached and public is public.

---

## Citation metadata (Zotero, Google Scholar, …)

So that the **Zotero Connector** and similar tools capture a resource page as a reference,
each item/publication page also emits embedded bibliographic `<meta>` tags — the two
vocabularies Zotero's *Embedded Metadata* translator reads:

* **Highwire Press** (`citation_title`, repeated `citation_author`, `citation_publication_date`,
  `citation_journal_title` / `citation_inbook_title` / `citation_conference_title`,
  `citation_volume`, `citation_issue`, `citation_firstpage`/`citation_lastpage`,
  `citation_doi`, `citation_isbn`, `citation_issn`, `citation_publisher`, `citation_language`
  (a BCP 47 tag), `citation_keywords`, `citation_abstract`, `citation_pdf_url`,
  `citation_public_url`) — for **scholarly papers only**: an article, conference paper,
  chapter, book, thesis or report / working paper / preprint that names at least one author.
  Google Scholar takes any page with `citation_title` for a paper, so research items,
  podcasts, videos, datasets, posts, journal issues and series editorships
  (`dre_seo.citation.highwire_excluded_templates`: 17, 29) and authority records carry none.
  The tag set is chosen per kind so Zotero infers the right **item type** (journal article,
  book section, book, conference paper, thesis, report).
* **Dublin Core** (`DC.title`, `DC.creator`, `DC.date`, `DC.publisher`, `DC.type`,
  `DC.language`, `DC.identifier`, `DC.subject`, `DC.description`) — emitted for **every**
  resource, including person/place/organisation pages. `DC.identifier` is the persistent
  identifier (above); a work's `DC.creator` is its citation authors (a podcast's host, a
  video's speakers).

The Highwire item-type mapping lives in `dre_seo.citation.template_kinds`
(`config/module.config.php`) and is overridable. The thesis templates (doctoral 16,
habilitation 30, master's 31, bachelor's 32) share the `thesis` kind; the degree comes from the
item's FaBiO class, so only a `fabio:DoctoralThesis` is cited as a PhD dissertation, and the
habilitation's plain `fabio:Thesis` reads as "Thesis".

Both template maps are keyed by instance-specific template ids, and a template neither names
falls back silently to `CreativeWork` / `item`. `tests/amira-templates.php` lists the AMIRA
instance's templates: when the site gains one, add it there and the suite fails until both
maps name it. `citation_pdf_url` is emitted only for a
**public** PDF media, so restricted bitstreams are never exposed. Toggle the whole feature with
**Emit citation meta tags** in Configure (on by default). The same tags double as
[Google Scholar](https://scholar.google.com) indexing signals.

Every work's `DC.type` names its exact Zotero item type where the Highwire tags cannot
(`thesis`, `report`, `book`, `bookSection`, `conferencePaper`, `blogPost`, `dataset`, `podcast`,
`videoRecording`): Zotero's *Embedded Metadata* translator accepts any exact Zotero item-type
id there, and it wins over the translator's `journalArticle` guess. The Highwire tags are
written from the same citation record as the formatted citations below, so Zotero, Scholar
and the record page agree on every field.

**Podcasts** (template 21) credit their host (`marcrel:hst`) in `DC.creator`; guests are not
authors. The series title and episode number have no flat-`<meta>` equivalent that Zotero
reads, so they are omitted from the tags; the JSON-LD carries them as `partOfSeries` and
`episodeNumber`.

**YouTube videos** (template 22) emit `DC.type=videoRecording`; manually curated
`marcrel:spk` values are exposed as creators.

Zotero does not read JSON-LD, and its COinS and unAPI translators outrank Embedded Metadata,
so the `<meta>` tags are — and should stay — the one channel it reads.

### Cite this record

The theme's record rail calls the `dreCitation($item)` view helper, which formats the record in
**Chicago** (18th ed.), **APA** (7th) and **MLA** (9th) and links BibTeX / RIS / CSL-JSON /
plain-text downloads at `/cite/{id}/{format}` (`txt` is the default style as UTF-8 text,
named `{accession}.txt`). The downloads carry `Access-Control-Allow-Origin: *`, an `ETag`,
the record's `Last-Modified` and `Cache-Control: public, max-age=300`, and answer a
conditional request with 304. The URL in every citation is the record's persistent
identifier (see [One identity per record](#one-identity-per-record)); a DOI, when the record
has one, is still the link the formatted styles print. A hand-entered `dcterms:bibliographicCitation` is offered
first, as *As catalogued*. Each document type follows its style's own form — a thesis names
its awarding institution (`marcrel:dgg`), a newspaper article its full date, a podcast its
host and episode, a YouTube video its platform, a research item its project and the archive.

**Authority records** — persons, places, organisations, projects, research sections, subject
headings and other vocabulary terms, journals — are not works and get no Highwire tags. They
are cited as entries of the database, the way Wikidata or the Deutsche Biographie cite an
index entry:

> “Aas, Norbert.” Person record. Africa Multiple Interactive Research Atlas (AMIRA). Africa
> Multiple Cluster of Excellence, University of Bayreuth. Last modified June 9, 2026.
> https://data.africamultiple.uni-bayreuth.de/s/amira/item/778.

The date is the record's last modification, which tells a reader which state of a changing
record was cited; it stands in for the access date the styles ask of changing pages. Entries
download as a web page (BibTeX `@online`, RIS `ELEC`, CSL `webpage` — the forms Zotero reads
back as one). The helper's `citeAs` key (`'work'` or `'entry'`) tells the theme which it is;
`citable` stays "is a work". The publisher is `dre_seo.citation.publisher`.

---

## Sitemap

* `/sitemap.xml` — sitemap **index** listing the children below.
* `/sitemap-pages.xml` — the home page (first, at its own page URL, matching its
  canonical) + all public site pages, less those an editor set to noindex.
* `/sitemap-item-sets.xml` — public item-set browse pages.
* `/sitemap-items-{n}.xml` — public items, chunked at 50,000 URLs per file.

Resource ids + modified timestamps are read with one lean DBAL query per type (public
resources scoped to the site), so even ~9k items render in well under a second. Omeka stores
`modified` as a naive UTC datetime, and `<lastmod>` is written in UTC to match. Output is
cached under `files/dre-seo-cache/`; cache failures fall back to live generation, and cache
or database failures are recorded in Omeka's log for diagnosis. While the whole site is set
to noindex, no sitemap is served and robots.txt names none.

---

## IndexNow ping — and the bulk-sync caveat

When **Ping IndexNow** is on, a public item, item set or page that is **created**,
**updated**, **made private** or **deleted** queues its page URL on the default site (the
home page at its own page URL, its canonical). The first edit dispatches one background job;
concurrent edits coalesce into that batch, and edits arriving while it runs schedule at most
one follow-up job. On MySQL/MariaDB the queue is read and written under a named lock
(`GET_LOCK`), straight from its settings rows, so two saves at once cannot drop a URL. A stale
15-minute queue lock recovers automatically if a worker exits unexpectedly. Transient
failures (no answer, 408, 429, 5xx) are retained and retried when a later content edit
schedules the queue again; a permanent refusal (400, 403, 422) is logged and dropped.

> **This site's content arrives mainly through the `MongoDB2OmekaS` bulk sync, which writes
> thousands of items.** The queue is therefore capped (200) and the job *skips* pinging when
> it looks like a bulk change — those URLs are discovered through the sitemap instead.
> IndexNow is meant for occasional **manual** edits. If in doubt, leave it **off** and rely on
> the sitemap + Search Console.

Google is **not** pinged: its sitemap-ping endpoint was retired in 2023. Google discovers
content via the robots.txt `Sitemap:` line and Search Console.

---

## Project layout

```
DRESeo/
├── .github/workflows/
│   ├── ci.yml                       # PHP 8.2 + production PHP 8.5 checks, Omeka smoke test
│   └── release.yml                  # tag -> DRESeo.zip + SHA-256 on the GitHub release
├── CHANGELOG.md
├── CITATION.cff                     # how to cite the module (GitHub / Zenodo read this)
├── LICENSE                          # GPL-3.0-or-later
├── Module.php                       # listeners, ACL, install/uninstall, config form
├── composer.json                    # type omeka-module; PSR-4 DRESeo\ -> src/ (no runtime deps)
├── config/
│   ├── module.ini
│   └── module.config.php            # routes, services, navigation, dre_seo defaults
├── src/
│   ├── Controller/
│   │   ├── SitemapController.php     # /sitemap*.xml, /robots.txt, /{key}.txt
│   │   └── Admin/SeoController.php   # dashboard + static-page table
│   ├── Form/{ConfigForm,PageSeoForm}.php
│   ├── Job/PingSearchEngines.php
│   └── Service/
│       ├── HeadMetadata.php          # computes + injects all <head> SEO
│       ├── StructuredData.php        # schema.org JSON-LD
│       ├── Signposting.php           # FAIR Signposting links + Link header
│       ├── LinkedDataIdentity.php    # resource URIs, sameAs, dataset from DRE Linked Data
│       ├── SiteHome.php              # the one home-page URL
│       ├── Licence.php               # licence → URL resolver (JSON-LD + Signposting)
│       ├── LanguageCode.php          # ISO 639-2/3 → BCP 47
│       ├── SearchPage.php            # search results routes (noindex)
│       ├── ImageDimensions.php       # og:image size of a local file
│       ├── HttpCache.php             # ETag / Last-Modified for /cite
│       ├── SitemapGenerator.php      # lean queries + XML + cache
│       ├── PageSeoStore.php          # per-page overrides (site setting)
│       ├── Pinger.php                # IndexNow submit
│       └── *Factory.php
├── view/dre-seo/admin/seo/{dashboard,pages}.phtml
├── asset/css/admin.css
├── tests/                            # unit harness, PHP lint runner, Omeka integration smoke
└── language/template.pot
```

`tests/` and the repository furniture are `export-ignore`d in `.gitattributes`, so the release
zip holds only what Omeka needs at runtime plus the README, licence and changelog. Run
`composer check` from a clone, not from an unpacked release.

## Development and testing

The unit harness is deliberately dependency-free: Omeka supplies Laminas, PSR and Doctrine
at runtime, so the module must not bundle duplicate framework packages. Run all checks with:

```bash
composer check
```

This syntax-checks every PHP/PHTML file and exercises sitemap XML generation and caching,
static-page override normalization, the current Africa Multiple template mapping, YouTube
`VideoObject` output, IndexNow key validation, and single-flight batching. GitHub Actions
runs the same command on PHP 8.2 and the production PHP 8.5 runtime. A separate job downloads
the checksum-pinned official Omeka S 4.2.1 release and exercises the module's real service
factories, shared event listeners, configuration form, Laminas head helpers, and DBAL-backed
sitemap generation. You can run it locally against an unpacked release with:

```bash
OMEKA_PATH=/path/to/omeka-s composer integration
```

---

## Verifying a deployment

1. `curl -s https://HOST/sitemap.xml | head` → a `<sitemapindex>`; the child sitemaps list
   `<url>` entries with `<lastmod>`.
2. `curl -s https://HOST/robots.txt` → `Disallow: /admin/` and a `Sitemap:` line.
3. View-source an item page (`/s/amira/item/100`): confirm `<title>`, `description`, `og:*`,
   `twitter:*`, `<link rel="canonical">` and a `application/ld+json` block. Validate the JSON-LD
   with the [Rich Results Test](https://search.google.com/test/rich-results). With DRE Linked
   Data installed, its `@id` is `https://HOST/resource/{id}`, and
   `curl -sI https://HOST/s/amira/item/100` shows the Signposting `Link` header.
4. Home page (`/s/amira/page/home`): `og:site_name`, `WebSite` and `Dataset` JSON-LD
   blocks, and a `<link rel="canonical">` pointing at that same page URL; the GSC tag once a
   token is set.
5. **Admin → SEO → Static pages**: set a title/description on a page → confirm it appears in
   that page's `<head>`.
6. In Search Console: add the property, verify via the meta tag, submit `/sitemap.xml`.

---

## Roadmap

* Not **COinS** or **unAPI**: Zotero ranks both translators ahead of Embedded Metadata, so
  either would replace the richer Highwire tags with a poorer reference.
* JSON-LD author `sameAs` from each linked person's GND / ORCID IRI (Signposting already
  links authors by ORCID).
* Share images of at least 1200 px: Omeka's `large` derivative is 800 px, below what Google
  Discover and large link previews ask for.
* Per-static-page SEO fields **inline in the page editor** (currently a central table).
* `hreflang` / multilingual metadata once localized page variants exist.
* Image / news sitemap extensions; optional nginx-level caching of `/sitemap*.xml`.

---

## Uninstalling

Removes every `dre_seo_*` global setting and the per-site `dre_seo_pages` overrides. The
sitemap cache directory (`files/dre-seo-cache/`) can be deleted manually if desired.

---

## Citing this module

Machine-readable metadata lives in [`CITATION.cff`](CITATION.cff), which GitHub renders under
**Cite this repository** in the sidebar:

> Madore, Frédérick. *DRE SEO: search-engine optimisation, structured data and citation
> metadata for Omeka S*. Africa Multiple Cluster of Excellence, University of Bayreuth.
> <https://github.com/AM-Digital-Research-Environment/DRE-SEO>

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup and the release procedure,
and [SECURITY.md](SECURITY.md) for reporting a vulnerability privately. Changes are recorded
in [CHANGELOG.md](CHANGELOG.md).

## Licence

[GPL-3.0-or-later](LICENSE). © Frédérick Madore / Africa Multiple Cluster of Excellence,
University of Bayreuth.
