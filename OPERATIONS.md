# DRE SEO operations

What the server needs beyond unpacking the module: the upgrade step, the IndexNow cron,
and what to do when IndexNow refuses a batch. The module's settings are described in the
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
the newer edit, whatever the older submission's outcome. A bulk change — a sync that saves
thousands of items — is queued in full and sent 200 URLs per run, about 57,600 a day at
the five-minute schedule.

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
