# DRE SEO operations

What the server needs beyond unpacking the module: the upgrade step, the IndexNow cron,
what to do when IndexNow refuses a batch, the instance configuration, and what to check on
the web server. The module's settings are described in the
[README](README.md#configuration).

## Upgrading

Replace the whole `modules/DRESeo/` folder with the new release, then run **Admin →
Modules → DRE SEO → Upgrade**. Omeka marks the module as needing an upgrade whenever the
version in `config/module.ini` is newer than the one it recorded, and keeps it inactive
until the upgrade has run. Keep the module installed: uninstalling removes its settings,
its per-page overrides and its queue.

The upgrade is safe to repeat. It:

- applies every default setting that is still unset, so a default introduced after the
  site's first install reaches it, and never overwrites a value an administrator chose;
- creates the IndexNow outbox table, `dre_seo_ping`, if it does not exist;
- moves the URLs that 0.15 and earlier still held in their settings-backed queue
  (`dre_seo_ping_pending`) into that table, then deletes `dre_seo_ping_pending` and
  `dre_seo_ping_job_queued`.

Take a normal database backup first. The table is the module's only schema change; it is
created with plain SQL that MySQL/MariaDB and SQLite both accept.

If an upgrade stops at `Class "DRESeo\…" not found`, PHP is still running the previous
release's `Module.php`, typically from OPcache with timestamp validation off: replace the
whole folder, restart PHP-FPM, and retry **Upgrade**.

## Instance configuration

What AMIRA's ids mean — the schema.org type and citation kind per resource template, the
breadcrumb landing pages, the robots.txt rules, the citation styles — is the `dre_seo` block
of `config/instance.config.php`, which ships with the module (0.15 and earlier kept it at
the end of `config/module.config.php`). Override a key from Omeka's
`config/local.config.php`, never by editing the module's files, which the next release
replaces:

```php
// config/local.config.php
return [
    'dre_seo' => [
        'citation' => ['publisher' => 'Africa Multiple Cluster of Excellence'],
        // A map keyed by id is replaced whole (see below).
        'breadcrumb' => ['landing_pages' => new \Laminas\Stdlib\ArrayUtils\MergeReplaceKey([
            20 => 'research-projects',
            29918 => 'publications',
        ])],
    ],
    // … Omeka's own keys …
];
```

Omeka merges `local.config.php` over the modules' configuration with Laminas'
`ArrayUtils::merge()`, so an override written for 0.15 still applies. Mind its rule for
integer keys: a string key replaces, a new id is added, but an id the module already maps
(a template id in `template_types` or `template_kinds`, an item set in `landing_pages`) is
*appended* under a new number rather than replaced. To change such an entry, replace the
whole map with `MergeReplaceKey`, as above. After changing anything that reaches a sitemap,
press **Admin → SEO → Regenerate**.

## The web server

**robots.txt** disallows the query variants of DRE Search's results page and of Omeka's
browse pages and allows their clean `?page=` pagination (the rules are `dre_seo.robots`,
described in the [README](README.md#robotstxt)). `curl -s https://HOST/robots.txt` should
show the `Allow: /s/*/item?page=` lines, `Disallow: /s/*/dre-search?` and a `Sitemap:`
line. Search Console's robots.txt report shows the copy Google fetched; Google rereads it
within a day. While **Discourage indexing** is on, the file is `Disallow: /` alone.

**The `Link` header.** Item, item-set and media pages repeat their FAIR Signposting links
as an HTTP `Link` header, capped at 2 KB. nginx answers `502 Bad Gateway` when a FastCGI
response's headers overflow `fastcgi_buffer_size` (one memory page, 4 KB on the usual Linux
host), and Omeka's session cookie and cache headers share that buffer; links that do not
fit stay in the page head only. If another module adds large headers of its own and record
pages answer 502, raise the buffer in the PHP location block:

```nginx
fastcgi_buffer_size 16k;
fastcgi_buffers 16 16k;
```

`curl -sI https://HOST/s/amira/item/{id}` shows the header; a `Link` header another module
sets is kept beside it.

## The IndexNow cron

With **Ping IndexNow when content changes** on, every public item, item set or default-site
page that is created, edited, made private or deleted puts its URL in `dre_seo_ping`. A
save also dispatches a drain job, at most once every 15 minutes; the cron sends whatever
is left without waiting for another edit. Install it as the user PHP-FPM runs as,
adjusting both paths:

```cron
*/5 * * * * OMEKA_PATH=/var/www/html /usr/bin/php /var/www/html/modules/DRESeo/scripts/drain-indexnow.php
```

`OMEKA_PATH` may be left out when the module sits in that installation's `modules/`
directory. The script refuses to run outside the command line (a web request gets a 404),
exits quietly while the module is inactive or IndexNow is off, and records an Omeka job
(**Admin → Jobs**) only when a URL is due, so a quiet site does not fill the job list.

Each run leases up to 200 URLs, submits them per origin, and settles each submission by
IndexNow's answer:

| Answer | What happens to the URLs |
|---|---|
| 200, 202 | Accepted: removed from the queue. |
| no response, 408, 429, 5xx | Retried with exponential backoff (after 2, 4, 8 and 16 minutes); after the fifth failed attempt they count as failed. |
| anything else (400, 403, 422, a 3xx) | Refused: they would fail the same way again, so they count as failed at once and are not resent. |

A job that dies mid-submission loses nothing: its lease runs out after ten minutes and
the next run sends the URLs again. A URL edited again while its batch was in flight keeps
the newer edit, whatever the older submission's outcome.

### Bulk changes are not pinged

A `MongoDB2OmekaS` sync re-saves thousands of records through the API, most of them
unchanged. When a run finds more than 200 URLs waiting for their first submission (more
than it can send, `PingQueue::BULK_THRESHOLD`), it takes them for such a sync: it removes
them from the queue without contacting IndexNow and logs one line in the job log,
`skipped IndexNow for a bulk change of N URL(s), most likely a sync; the sitemap covers
their discovery`. The sitemap lists every one of them, and the same saves cleared its
cache. URLs being retried after a transient failure, and those re-queued with
`--retry-failed`, were submitted before and go out 200 per run however many there are.

The guard weighs what accumulated since the previous run, so it depends on the schedule:
at five minutes it catches a sync that saves more than 200 public records in that time, and
a slower one is sent like editing. Without the cron, the next run comes with a save at
least 15 minutes after the last dispatch, and the first few URLs of a sync go out before
the rest is skipped. A skipped URL is gone from the queue; edit the record again (or wait
for the crawler to reach it through the sitemap) to have it pinged.

## Failed URLs

**Admin → SEO** shows the queue's pending and failed counts. The job log of the run that
failed (**Admin → Jobs → the job → Log**) says what IndexNow answered. A refusal names the
status and the key location: 403 means IndexNow could not fetch the key at
`https://HOST/{key}.txt`; 422 means the URLs are not of the submitted host.

Fix the cause, then re-queue every failed URL once:

```sh
OMEKA_PATH=/var/www/html php /var/www/html/modules/DRESeo/scripts/drain-indexnow.php --retry-failed
```

It resets the failed URLs to a fresh start and sends the first batch straight away.

## The sitemap cache

The sitemaps are cached in `files/dre-seo-cache/` (under Omeka's `file_store.local.base_path`),
which the PHP-FPM user must be able to write; if it cannot, Omeka's log says so and every
request builds the sitemap live. Besides the `*.xml` documents the directory holds a
`generation` stamp, one `.generation` sidecar per document and the lock files; leave them to
the module. Any create, edit or delete of an item, item set, site page, media or site clears
the cache; **Admin → SEO → Regenerate** does the same by hand, which is only needed after a
change that bypasses Omeka (a database import) or after editing `dre_seo.sitemap` in
`config/local.config.php`.

While the database fails, crawlers get the last copy if it expired less than five minutes
ago, and otherwise `503 Temporarily unavailable` with `Retry-After: 300`, never an empty
sitemap. Both are logged.

## Uninstalling

Uninstall drops the `dre_seo_ping` table with any URLs still in it, every `dre_seo_*`
global setting (the 0.15 queue settings included), the per-site settings and the sitemap
cache in `files/dre-seo-cache/`. Remove the cron line as well; until then it exits
quietly.
