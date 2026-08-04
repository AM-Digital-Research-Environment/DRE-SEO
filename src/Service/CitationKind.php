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
     * no citation, no export, and no Highwire tags either, since a subject
     * heading offered to Zotero as a scholarly article is worse than nothing.
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
            default => 'GEN',
        };
    }

    /**
     * The creator roles this kind reads, in preference order, ahead of the
     * generic author terms. Podcasts and videos carry no dcterms:creator — the
     * host, guest and speaker roles are where their people live (the same
     * fallbacks CitationMeta uses for the Highwire tags).
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
}
