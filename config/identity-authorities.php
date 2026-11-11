<?php
declare(strict_types=1);

/**
 * The authorities whose IRIs are identity links, shared by DRE Linked Data
 * (owl:sameAs / skos:exactMatch in the RDF, the VoID linksets, the
 * `dreLinkedData` helper's `sameAs`) and DRE SEO (JSON-LD `sameAs`, ORCID for
 * Signposting), so a record names the same targets whichever is active.
 *
 * DRE Linked Data's config/identity-authorities.php is the source. DRE SEO
 * carries a byte-identical copy at config/identity-authorities.php. Change the
 * Linked Data copy, copy it over, and update the sha256 both repositories pin
 * in their tests, beside that of identity-links.json, the cases both run.
 * The two copies must stay identical.
 *
 * pattern:   PCRE matched against the stored URI, trimmed. Tolerant on input:
 *            http or https, scheme and host in any case, the `www.`, `m.` or
 *            `sws.` host the authority itself uses, a trailing slash. The id is
 *            case-sensitive, and a query or fragment is no match.
 * canonical: its replacement, always the authority's own entity IRI.
 * label, dataset, uri_space: the VoID linkset target (DRE Linked Data only).
 *
 * Only these qualify. A YouTube playlist, a GEPRIS page or a free shelf mark
 * in dcterms:identifier is not the same thing as the record. DOIs are not
 * here: they come from bibo:doi, by a rule each module implements and both
 * test against the same fixture.
 *
 * @return array<string,array{label:string,pattern:string,canonical:string,dataset:string,uri_space:string}>
 */
return [
    'wikidata' => [
        'label' => 'Wikidata',
        'pattern' => '~^(?i:https?://(?:www\.|m\.)?wikidata\.org)/(?:entity|wiki)/(Q[1-9][0-9]*)/?$~',
        'canonical' => 'http://www.wikidata.org/entity/$1',
        'dataset' => 'https://www.wikidata.org/',
        'uri_space' => 'http://www.wikidata.org/entity/',
    ],
    'loc' => [
        'label' => 'Library of Congress',
        'pattern' => '~^(?i:https?://id\.loc\.gov)/authorities/(subjects|names|genreForms)/([a-z]+[0-9]+)(?:\.html|/)?$~',
        'canonical' => 'http://id.loc.gov/authorities/$1/$2',
        'dataset' => 'http://id.loc.gov/',
        'uri_space' => 'http://id.loc.gov/authorities/',
    ],
    'gnd' => [
        'label' => 'GND',
        'pattern' => '~^(?i:https?://d-nb\.info)/gnd/([0-9]+(?:X|-[0-9X])?)/?$~',
        'canonical' => 'https://d-nb.info/gnd/$1',
        'dataset' => 'https://d-nb.info/gnd/',
        'uri_space' => 'https://d-nb.info/gnd/',
    ],
    'viaf' => [
        'label' => 'VIAF',
        'pattern' => '~^(?i:https?://(?:www\.)?viaf\.org)/viaf/([0-9]+)/?$~',
        'canonical' => 'http://viaf.org/viaf/$1',
        'dataset' => 'http://viaf.org/',
        'uri_space' => 'http://viaf.org/viaf/',
    ],
    'orcid' => [
        'label' => 'ORCID',
        'pattern' => '~^(?i:https?://(?:www\.)?orcid\.org)/([0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X])/?$~',
        'canonical' => 'https://orcid.org/$1',
        'dataset' => 'https://orcid.org/',
        'uri_space' => 'https://orcid.org/',
    ],
    'isni' => [
        'label' => 'ISNI',
        'pattern' => '~^(?i:https?://(?:www\.)?isni\.org)/isni/([0-9]{15}[0-9X])/?$~',
        'canonical' => 'https://isni.org/isni/$1',
        'dataset' => 'https://isni.org/',
        'uri_space' => 'https://isni.org/isni/',
    ],
    'ror' => [
        'label' => 'ROR',
        'pattern' => '~^(?i:https?://(?:www\.)?ror\.org)/(0[a-z0-9]{8})/?$~',
        'canonical' => 'https://ror.org/$1',
        'dataset' => 'https://ror.org/',
        'uri_space' => 'https://ror.org/',
    ],
    'geonames' => [
        'label' => 'GeoNames',
        'pattern' => '~^(?i:https?://(?:www\.|sws\.)?geonames\.org)/([0-9]+)(?:/.*)?$~',
        'canonical' => 'https://sws.geonames.org/$1/',
        'dataset' => 'https://www.geonames.org/',
        'uri_space' => 'https://sws.geonames.org/',
    ],
];
