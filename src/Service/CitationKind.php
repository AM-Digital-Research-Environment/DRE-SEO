<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * The closed set of citation kinds DRE resources are dispatched to, and the
 * export-format type mappings that are a pure function of the kind.
 *
 * A kind is resolved from an Omeka **resource template** id through the
 * configured `dre_seo.citation.template_kinds` map — see {@see CitationKindMap}.
 * Templates, not resource classes: the DRE's templates are the authoritative
 * distinction (templates 11-20 are the publication types, 21 podcasts, 22
 * YouTube videos), and several of them share a class.
 *
 * The type tables live here rather than in the services that consume them, so a
 * new kind cannot be added without the compiler asking for its types.
 */
enum CitationKind: string
{
    // Authority entities — descriptive records, not citable works.
    case Person = 'person';
    case Place = 'place';
    case Organization = 'organization';
    case Project = 'project';
    case Section = 'section';
    /**
     * The Authority Resource template (6), which carries every controlled
     * vocabulary the archive keeps: subjects, languages, genres, resource
     * types, target audiences, sponsors, playlists. One template for all of
     * them, where IWAC had a resource class per kind — which is how these
     * came to be treated as citable works when the citation stack was ported.
     */
    case Authority = 'authority';
    /** A publication venue (template 23), not a work that is cited on its own. */
    case Journal = 'journal';

    // Bibliographic works.
    case Article = 'article';
    case Conference = 'conference';
    case Chapter = 'chapter';
    case Book = 'book';
    case Thesis = 'thesis';
    case Report = 'report';
    case Post = 'post';
    case Dataset = 'dataset';
    case Podcast = 'podcast';
    case Video = 'video';

    /** The fallback for a resource template with no mapping (research items). */
    case Item = 'item';

    /**
     * Descriptive records — a person, a place, an organisation, a project, a
     * research section, a vocabulary term, a journal — are not citable works:
     * no Highwire tags, since a subject heading offered to Zotero or Google
     * Scholar as a scholarly article is worse than nothing.
     *
     * They are still citable as what they are: an entry in the AMIRA database,
     * the way a reader cites a dictionary entry or an authority file. That
     * entry citation names the record, the database and the date the record
     * last changed, and claims no author ({@see CitationFormatter}).
     *
     * This is the single source of truth for that question. {@see CitationMeta}
     * used to keep its own list of kind strings, which is why the Authority
     * Resource template went on emitting citation_title for terms like
     * "Artefact" long after the panel had learned better.
     */
    public function isAuthorityRecord(): bool
    {
        return match ($this) {
            self::Person, self::Place, self::Organization, self::Project,
            self::Section, self::Authority, self::Journal => true,
            default => false,
        };
    }

    /**
     * Scholarly literature in the sense Google Scholar indexes it: articles,
     * conference papers, chapters, books, theses and reports (working papers,
     * preprints). Only these carry Highwire `citation_*` tags, and only when
     * they name an author ({@see CitationMeta}). Research items, posts,
     * datasets, podcasts and videos are works too — cited, exported and
     * described in Dublin Core — but Scholar treats a page with
     * `citation_title` as a paper, and a field recording or a video is none.
     */
    public function isScholarly(): bool
    {
        return match ($this) {
            self::Article, self::Conference, self::Chapter, self::Book,
            self::Thesis, self::Report => true,
            default => false,
        };
    }

    /**
     * Works published *inside* a container. Drives title treatment: quoted
     * (Chicago/MLA) or plain (APA) with an italic container, rather than an
     * italic standalone title.
     */
    public function isPartOfWork(): bool
    {
        return match ($this) {
            self::Article, self::Conference, self::Chapter, self::Post, self::Podcast => true,
            default => false,
        };
    }

    /** CSL item type — drives CSL-JSON export and downstream typing. */
    public function cslType(): string
    {
        return match ($this) {
            self::Article => 'article-journal',
            self::Conference => 'paper-conference',
            self::Chapter => 'chapter',
            self::Book => 'book',
            self::Thesis => 'thesis',
            self::Report => 'report',
            self::Post => 'post-weblog',
            self::Dataset => 'dataset',
            self::Podcast => 'broadcast',
            self::Video => 'motion_picture',
            // A database entry is cited as a page of the website that holds
            // it — CSL's `webpage`, which Zotero imports as a Web Page.
            self::Person, self::Place, self::Organization, self::Project,
            self::Section, self::Authority, self::Journal => 'webpage',
            default => 'document',
        };
    }

    /**
     * BibTeX entry type. `@dataset` is biblatex-native (classic BibTeX has no
     * data type and Zotero/JabRef both import it); podcasts and videos fall to
     * `@misc`, which is what Zotero itself exports them as.
     */
    public function bibtexType(): string
    {
        return match ($this) {
            self::Article => 'article',
            self::Conference => 'inproceedings',
            self::Chapter => 'incollection',
            self::Book => 'book',
            self::Thesis => 'phdthesis',
            self::Report => 'techreport',
            self::Post => 'online',
            self::Dataset => 'dataset',
            self::Person, self::Place, self::Organization, self::Project,
            self::Section, self::Authority, self::Journal => 'online',
            default => 'misc',
        };
    }

    /** RIS reference type (the TY tag), following Zotero's own RIS mappings. */
    public function risType(): string
    {
        return match ($this) {
            self::Article => 'JOUR',
            self::Conference => 'CONF',
            self::Chapter => 'CHAP',
            self::Book => 'BOOK',
            self::Thesis => 'THES',
            self::Report => 'RPRT',
            self::Post => 'BLOG',
            self::Dataset => 'DATA',
            self::Podcast => 'SOUND',
            self::Video => 'VIDEO',
            // ELEC (web page) is the type Zotero and EndNote both read back as a
            // web page; DBASE would claim the record is the whole database.
            self::Person, self::Place, self::Organization, self::Project,
            self::Section, self::Authority, self::Journal => 'ELEC',
            default => 'GEN',
        };
    }

    /**
     * The Zotero item type the Embedded Metadata translator should create, sent
     * as an exact type id in DC.type (the translator accepts any id it knows
     * and lets it win over its citation_title guess). Null leaves detection to
     * the Highwire container tags: citation_journal_title already says "journal
     * article", and a research item is no single Zotero type.
     */
    public function zoteroType(): ?string
    {
        return match ($this) {
            self::Conference => 'conferencePaper',
            self::Chapter => 'bookSection',
            self::Book => 'book',
            self::Thesis => 'thesis',
            self::Report => 'report',
            self::Post => 'blogPost',
            self::Dataset => 'dataset',
            self::Podcast => 'podcast',
            self::Video => 'videoRecording',
            default => null,
        };
    }

    /**
     * What an entry citation calls the record ("Person record"); null for a
     * work, which is described by its own title and container instead.
     */
    public function entryLabel(): ?string
    {
        return match ($this) {
            self::Person => 'Person record',
            self::Place => 'Place record',
            self::Organization => 'Organisation record',
            self::Project => 'Project record',
            self::Section => 'Research section record',
            self::Authority => 'Authority record',
            self::Journal => 'Journal record',
            default => null,
        };
    }

    /**
     * The creator roles this kind reads, in preference order, ahead of the
     * generic author terms. Podcasts and videos carry no dcterms:creator — the
     * host, guest and speaker roles are where their people live (the same
     * fallbacks CitationMeta uses for the Highwire tags).
     *
     * A podcast's host is credited as such ("host"), which is why the first
     * term is also the role {@see roleFor()} reports.
     *
     * @return string[]
     */
    public function creatorTerms(): array
    {
        return match ($this) {
            self::Podcast => ['marcrel:hst', 'marcrel:spk'],
            self::Video => ['marcrel:spk'],
            default => [],
        };
    }

    /**
     * The role label a creator read from $term carries in a citation — the
     * podcast host is "host" (Chicago, MLA) or "(Host)" (APA). Null for every
     * other role, which the styles print without a label.
     */
    public function roleFor(string $term): ?string
    {
        return $this === self::Podcast && $term === 'marcrel:hst' ? 'host' : null;
    }
}
