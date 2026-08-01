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
            public function __construct(
                private readonly string $value = '',
                private readonly ?AbstractResourceEntityRepresentation $resource = null,
                private readonly ?string $uriValue = null,
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
            ) {
            }

            public function displayTitle(): string
            {
                return $this->title;
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
                return $this->classLabel === null
                    ? null
                    : new class ($this->classLabel) {
                        public function __construct(private readonly string $label)
                        {
                        }

                        public function label(): string
                        {
                            return $this->label;
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
        }
    }

    if (!class_exists(SiteRepresentation::class)) {
        class SiteRepresentation
        {
            public function __construct(
                private readonly string $slugValue = 'amira',
                private readonly string $titleValue = 'AMIRA',
            ) {
            }

            public function slug(): string
            {
                return $this->slugValue;
            }

            public function title(): string
            {
                return $this->titleValue;
            }
        }
    }
}

namespace {
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
