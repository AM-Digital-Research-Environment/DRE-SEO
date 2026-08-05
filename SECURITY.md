# Security policy

## Supported versions

Only the latest release receives fixes. Older tags are not patched.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it through GitHub's private advisory form:
[Report a vulnerability](https://github.com/AM-Digital-Research-Environment/DRE-SEO/security/advisories/new)

Or email the maintainer at the address on <https://www.frederickmadore.com/>.

Please include the module version, the Omeka S and PHP versions, and enough detail to
reproduce. You will get an acknowledgement within a week; this is a research-infrastructure
project maintained alongside other work, so please allow reasonable time for a fix before
disclosing publicly.

## Scope

This module renders metadata into public pages, serves `/sitemap*.xml`, `/robots.txt`, an
IndexNow key file and `/cite` exports, stores settings entered by administrators, and makes
outbound IndexNow requests. Findings of the following kinds are in scope:

- Injection through metadata that reaches `<head>`, JSON-LD, or a BibTeX/RIS/CSL-JSON export
- Non-public or private resources appearing in a sitemap, citation export, or meta tag
- Privilege issues in the admin SEO pages or the module configuration form
- Server-side request forgery or credential leakage via the IndexNow ping

The Google Search Console verification snippet is, by design, pasted by an administrator and
injected verbatim site-wide. That is an authenticated-admin capability equivalent to theme
editing, not a vulnerability in itself.

Vulnerabilities in Omeka S core belong to the [Omeka project](https://omeka.org/s/).
