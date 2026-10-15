<?php
declare(strict_types=1);

namespace Doctrine\DBAL {
    if (!class_exists(Connection::class)) {
        class Connection
        {
            public function fetchOne(string $query, array $params = []): mixed
            {
                throw new \LogicException('Test double must implement fetchOne().');
            }

            /** @return array<int,array<string,mixed>> */
            public function fetchAllAssociative(string $query, array $params = []): array
            {
                throw new \LogicException('Test double must implement fetchAllAssociative().');
            }
        }
    }
}

namespace Laminas\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
            public function emerg($message, $extra = []);
            public function alert($message, $extra = []);
            public function crit($message, $extra = []);
            public function err($message, $extra = []);
            public function warn($message, $extra = []);
            public function notice($message, $extra = []);
            public function info($message, $extra = []);
            public function debug($message, $extra = []);
        }
    }
}

namespace Omeka\Job {
    if (!class_exists(Dispatcher::class)) {
        class Dispatcher
        {
            /** @var array<int,array{class:string,args:mixed}> */
            public array $dispatched = [];
            public bool $fail = false;

            public function dispatch($class, $args = null, $strategy = null): object
            {
                if ($this->fail) {
                    throw new \RuntimeException('Simulated dispatch failure.');
                }
                $this->dispatched[] = ['class' => (string) $class, 'args' => $args];
                return new \stdClass();
            }
        }
    }
}

namespace Omeka\Settings {
    if (!class_exists(Settings::class)) {
        class Settings
        {
            /** @var array<string,mixed> */
            private array $values = [];

            public function get($id, $default = null): mixed
            {
                return $this->values[$id] ?? $default;
            }

            public function set($id, $value): void
            {
                $this->values[$id] = $value;
            }

            public function delete($id): void
            {
                unset($this->values[$id]);
            }
        }
    }

    if (!class_exists(SiteSettings::class)) {
        class SiteSettings
        {
            private int $targetId = 0;

            /** @var array<int,array<string,mixed>> */
            private array $values = [];

            public function setTargetId(int $targetId): void
            {
                $this->targetId = $targetId;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$this->targetId][$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$this->targetId][$key] = $value;
            }
        }
    }
}

namespace Omeka\Api\Representation {
    if (!class_exists(ValueRepresentation::class)) {
        class ValueRepresentation
        {
            /**
             * @param string|null $rawValue what value() returns — the stored form,
             *   which for a date keeps the month/day the rendered string may drop.
             * @param string|null $dataType overrides the type inferred from the
             *   other arguments.
             */
            public function __construct(
                private readonly string $value = '',
                private readonly ?AbstractResourceEntityRepresentation $resource = null,
                private readonly ?string $uriValue = null,
                private readonly ?string $rawValue = null,
                private readonly ?string $dataType = null,
            ) {
            }

            public function __toString(): string
            {
                return $this->value;
            }

            public function valueResource(): ?AbstractResourceEntityRepresentation
            {
                return $this->resource;
            }

            public function uri(): ?string
            {
                return $this->uriValue;
            }

            /** The stored value, as Omeka's ValueRepresentation::value() returns it. */
            public function value(): ?string
            {
                return $this->rawValue ?? $this->value;
            }

            public function type(): string
            {
                if ($this->dataType !== null) {
                    return $this->dataType;
                }
                if ($this->resource !== null) {
                    return 'resource:item';
                }

                return $this->uriValue !== null ? 'uri' : 'literal';
            }
        }
    }

    if (!class_exists(AbstractResourceEntityRepresentation::class)) {
        class AbstractResourceEntityRepresentation
        {
            /**
             * @param array<string,ValueRepresentation[]> $values
             */
            public function __construct(
                private readonly string $title,
                private readonly ?int $templateId = null,
                private readonly ?string $classLabel = null,
                private readonly array $values = [],
                private readonly ?string $publicUrl = null,
                private readonly int $idValue = 1,
                /** The class's vocabulary term, e.g. 'fabio:MastersThesis'. */
                private readonly ?string $classTerm = null,
                /** o:modified, as 'Y-m-d' or a full timestamp. */
                private readonly ?string $modifiedValue = null,
                /** @var array<int,object> media attached to the item */
                private readonly array $mediaList = [],
            ) {
            }

            public function id(): int
            {
                return $this->idValue;
            }

            public function modified(): ?\DateTime
            {
                return $this->modifiedValue === null
                    ? null
                    : new \DateTime($this->modifiedValue, new \DateTimeZone('UTC'));
            }

            public function displayTitle(?string $default = null): string
            {
                return $this->title !== '' ? $this->title : (string) $default;
            }

            /** Whether the record is public; a test makes one private. */
            public bool $publicValue = true;

            public function isPublic(): bool
            {
                return $this->publicValue;
            }

            /** @return array<int,object> the media the stub was given. */
            public function media(): array
            {
                return $this->mediaList;
            }

            public function primaryMedia(): ?object
            {
                return $this->mediaList[0] ?? null;
            }

            public function resourceTemplate(): ?object
            {
                return $this->templateId === null
                    ? null
                    : new class ($this->templateId) {
                        public function __construct(private readonly int $id)
                        {
                        }

                        public function id(): int
                        {
                            return $this->id;
                        }
                    };
            }

            public function resourceClass(): ?object
            {
                return $this->classLabel === null && $this->classTerm === null
                    ? null
                    : new class ((string) $this->classLabel, (string) $this->classTerm) {
                        public function __construct(
                            private readonly string $label,
                            private readonly string $term,
                        ) {
                        }

                        public function label(): string
                        {
                            return $this->label;
                        }

                        public function term(): string
                        {
                            return $this->term;
                        }
                    };
            }

            public function value(string $term, array $options = []): mixed
            {
                $values = $this->values[$term] ?? [];
                return !empty($options['all']) ? $values : ($values[0] ?? null);
            }

            public function siteUrl(string $siteSlug, bool $canonical = false): string
            {
                return $this->publicUrl ?? 'https://example.test/s/' . $siteSlug . '/item/1';
            }

            public function apiUrl(): string
            {
                return 'https://example.test/api/items/' . $this->idValue;
            }
        }
    }

    if (!class_exists(ItemRepresentation::class)) {
        class ItemRepresentation extends AbstractResourceEntityRepresentation
        {
            /** @var array<int,ItemSetRepresentation> keyed by id, as Omeka keys them */
            public array $itemSetList = [];

            /** @return array<int,ItemSetRepresentation> */
            public function itemSets(): array
            {
                return $this->itemSetList;
            }
        }
    }

    if (!class_exists(ItemSetRepresentation::class)) {
        class ItemSetRepresentation extends AbstractResourceEntityRepresentation
        {
        }
    }

    if (!class_exists(MediaRepresentation::class)) {
        /** A media record: its file, and the item it belongs to. */
        class MediaRepresentation extends AbstractResourceEntityRepresentation
        {
            public ?ItemRepresentation $parent = null;
            public ?string $originalUrlValue = null;
            public ?string $mediaTypeValue = null;
            public bool $publicValue = true;
            public ?string $largeThumbnail = null;

            public function item(): ItemRepresentation
            {
                return $this->parent ?? new ItemRepresentation('Parent item');
            }

            public function originalUrl(): ?string
            {
                return $this->originalUrlValue;
            }

            public function mediaType(): ?string
            {
                return $this->mediaTypeValue;
            }

            public function isPublic(): bool
            {
                return $this->publicValue;
            }

            public function hasThumbnails(): bool
            {
                return $this->largeThumbnail !== null;
            }

            public function thumbnailUrl(string $type = 'square'): ?string
            {
                return $this->largeThumbnail;
            }
        }
    }

    if (!class_exists(SiteRepresentation::class)) {
        class SiteRepresentation
        {
            /** @param object[] $linkedPagesValue */
            public function __construct(
                private readonly string $slugValue = 'amira',
                private readonly string $titleValue = 'AMIRA',
                private readonly ?object $homepageValue = null,
                private readonly array $linkedPagesValue = [],
                private readonly int $idValue = 1,
            ) {
            }

            public function id(): int
            {
                return $this->idValue;
            }

            public function slug(): string
            {
                return $this->slugValue;
            }

            public function title(): string
            {
                return $this->titleValue;
            }

            public function homepage(): ?object
            {
                return $this->homepageValue;
            }

            /** @return object[] */
            public function linkedPages(): array
            {
                return $this->linkedPagesValue;
            }

            /** @var int[] ids of the item sets assigned to the site */
            public array $siteItemSetIds = [];

            /** @return object[] SiteItemSetRepresentation stand-ins */
            public function siteItemSets(): array
            {
                return array_map(
                    static fn (int $id): object => new class ($id) {
                        public function __construct(private readonly int $id)
                        {
                        }

                        public function itemSet(): ItemSetRepresentation
                        {
                            return new ItemSetRepresentation('Item set ' . $this->id, idValue: $this->id);
                        }
                    },
                    $this->siteItemSetIds
                );
            }

            /** @var SitePageRepresentation[] the site's pages, public or not */
            public array $pagesList = [];

            /** @return SitePageRepresentation[] */
            public function pages(): array
            {
                return $this->pagesList;
            }
        }
    }

    if (!class_exists(SitePageRepresentation::class)) {
        class SitePageRepresentation
        {
            public function __construct(
                private readonly string $slugValue = 'home',
                private readonly string $titleValue = 'Home',
                private readonly int $idValue = 10,
                private readonly bool $publicValue = true,
            ) {
            }

            public function id(): int
            {
                return $this->idValue;
            }

            public function isPublic(): bool
            {
                return $this->publicValue;
            }

            public function slug(): string
            {
                return $this->slugValue;
            }

            public function title(): string
            {
                return $this->titleValue;
            }

            public function siteUrl(?string $siteSlug = null, bool $canonical = false): string
            {
                return 'https://example.test/s/' . ($siteSlug ?? 'amira') . '/page/' . $this->slugValue;
            }
        }
    }
}

namespace Laminas\View\Renderer {
    if (!class_exists(PhpRenderer::class)) {
        /**
         * A head-helper recorder standing in for Laminas' renderer: every
         * <meta>, <link> and JSON-LD script a service writes lands in $head.
         */
        class PhpRenderer
        {
            /** @var array<string,array<int,mixed>> */
            public array $head = ['meta' => [], 'link' => [], 'script' => [], 'title' => []];

            /** @var array<string,mixed> */
            public array $vars = [];

            public function __construct(
                public string $currentUrl = 'https://example.test/s/amira/page/home',
                public string $origin = 'https://example.test',
            ) {
            }

            public function serverUrl($requestUri = null): string
            {
                if ($requestUri === true) {
                    return $this->currentUrl;
                }
                return $this->origin . (is_string($requestUri) ? $requestUri : '');
            }

            public function url($name = null, array $params = [], $options = []): string
            {
                $path = $name === 'site' ? '/s/' . ($params['site-slug'] ?? '') : '/' . (string) $name;
                return !empty($options['force_canonical']) ? $this->origin . $path : $path;
            }

            public function headMeta(): object
            {
                return new class ($this) {
                    public function __construct(private readonly PhpRenderer $view)
                    {
                    }

                    private function put(string $kind, string $key, string $content, bool $replace): self
                    {
                        if ($replace) {
                            $this->view->head['meta'] = array_values(array_filter(
                                $this->view->head['meta'],
                                static fn (array $m): bool => !($m[0] === $kind && $m[1] === $key)
                            ));
                        }
                        $this->view->head['meta'][] = [$kind, $key, $content];
                        return $this;
                    }

                    public function setName(string $key, string $content): self
                    {
                        return $this->put('name', $key, $content, true);
                    }

                    public function appendName(string $key, string $content): self
                    {
                        return $this->put('name', $key, $content, false);
                    }

                    public function setProperty(string $key, string $content): self
                    {
                        return $this->put('property', $key, $content, true);
                    }
                };
            }

            public function headLink($attributes = null): object
            {
                if (is_array($attributes)) {
                    $this->head['link'][] = $attributes;
                }
                return $this;
            }

            public function headScript(): object
            {
                return new class ($this) {
                    public function __construct(private readonly PhpRenderer $view)
                    {
                    }

                    public function appendScript(string $script, string $type = 'text/javascript', array $attrs = []): self
                    {
                        $this->view->head['script'][] = $script;
                        return $this;
                    }
                };
            }

            public function headTitle(): object
            {
                return new class ($this) {
                    public function __construct(private readonly PhpRenderer $view)
                    {
                    }

                    public function getContainer(): \ArrayObject
                    {
                        return new class ($this->view) extends \ArrayObject {
                            public function __construct(private readonly PhpRenderer $view)
                            {
                                parent::__construct($view->head['title']);
                            }

                            public function exchangeArray(object|array $array): array
                            {
                                $this->view->head['title'] = (array) $array;
                                return parent::exchangeArray($array);
                            }
                        };
                    }

                    public function append(string $title): self
                    {
                        $this->view->head['title'][] = $title;
                        return $this;
                    }
                };
            }

            public function getHelperPluginManager(): object
            {
                return new class {
                    public function has(string $name): bool
                    {
                        return false;
                    }
                };
            }

            /** @return string[] every content written under a <meta> name or property */
            public function meta(string $key): array
            {
                return array_values(array_map(
                    static fn (array $m): string => $m[2],
                    array_filter($this->head['meta'], static fn (array $m): bool => $m[1] === $key)
                ));
            }

            /** @return array<int,array<string,string>> the <link> elements with this rel */
            public function links(string $rel): array
            {
                return array_values(array_filter(
                    $this->head['link'],
                    static fn (array $l): bool => ($l['rel'] ?? '') === $rel
                ));
            }

            /** @return array<int,array<mixed>> the JSON-LD documents, decoded */
            public function jsonLd(): array
            {
                return array_map(static fn (string $json): array => json_decode($json, true), $this->head['script']);
            }
        }
    }
}

namespace {
    // The module's own classes, as Omeka's PSR-4 autoloader would find them.
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'DRESeo\\')) {
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen('DRESeo\\'))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    final class MemoryLogger implements \Laminas\Log\LoggerInterface
    {
        /** @var array<string,array<int,array{message:string,extra:mixed}>> */
        public array $records = [];

        private function record(string $level, $message, $extra): self
        {
            $this->records[$level][] = ['message' => (string) $message, 'extra' => $extra];
            return $this;
        }

        public function emerg($message, $extra = []): self { return $this->record('emerg', $message, $extra); }
        public function alert($message, $extra = []): self { return $this->record('alert', $message, $extra); }
        public function crit($message, $extra = []): self { return $this->record('crit', $message, $extra); }
        public function err($message, $extra = []): self { return $this->record('err', $message, $extra); }
        public function warn($message, $extra = []): self { return $this->record('warn', $message, $extra); }
        public function notice($message, $extra = []): self { return $this->record('notice', $message, $extra); }
        public function info($message, $extra = []): self { return $this->record('info', $message, $extra); }
        public function debug($message, $extra = []): self { return $this->record('debug', $message, $extra); }
    }

    /** @var array<string,Closure():void> $tests */
    $tests = [];

    function test(string $name, Closure $callback): void
    {
        global $tests;
        $tests[$name] = $callback;
    }

    function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $detail = sprintf(
                "Expected %s, got %s",
                var_export($expected, true),
                var_export($actual, true)
            );
            throw new RuntimeException($message !== '' ? $message . ': ' . $detail : $detail);
        }
    }

    /** @return string[] */
    function xmlLocations(string $xml, string $element): array
    {
        $document = new DOMDocument();
        assertTrue($document->loadXML($xml), 'Generated sitemap must be valid XML');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $nodes = $xpath->query('//s:' . $element . '/s:loc');
        assertTrue($nodes !== false, 'Sitemap XPath query failed');
        $locations = [];
        foreach ($nodes as $node) {
            $locations[] = $node->textContent;
        }
        return $locations;
    }
}
