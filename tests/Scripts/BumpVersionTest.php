<?php
declare(strict_types=1);

namespace DRESeo\Test\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * .github/scripts/bump-version.php, run as the maintainer and the release
 * workflow run it, on a scratch copy of the three files that state the version.
 */
final class BumpVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dre-seo-bump-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0777, true);
        file_put_contents($this->root . '/config/module.ini', "[info]\nname = \"DRE SEO\"\nversion           = \"1.2.0\"\n");
        file_put_contents($this->root . '/CITATION.cff', "cff-version: 1.2.0\nversion: 1.2.0\ndate-released: \"2026-01-01\"\n");
        file_put_contents($this->root . '/CHANGELOG.md', implode("\n", [
            '# Changelog',
            '',
            '## [Unreleased]',
            '',
            '### Fixed',
            '',
            '- Something.',
            '',
            '## [1.2.0] - 2026-01-01',
            '',
            '- Before.',
            '',
            '[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v1.2.0...HEAD',
            '[1.2.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v1.1.0...v1.2.0',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        foreach (['config/module.ini', 'CITATION.cff', 'CHANGELOG.md'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root . '/config');
        @rmdir($this->root);
    }

    public function testABumpRewritesEveryDeclarationAndTheCheckThenPasses(): void
    {
        $this->assertSame(0, $this->script('1.3.0', '--date=2026-11-10'));

        $this->assertStringContainsString('version           = "1.3.0"', $this->read('config/module.ini'));
        $this->assertStringContainsString("version: 1.3.0\ndate-released: \"2026-11-10\"", $this->read('CITATION.cff'));
        $changelog = $this->read('CHANGELOG.md');
        $this->assertStringContainsString("## [Unreleased]\n\n## [1.3.0] - 2026-11-10\n\n### Fixed", $changelog);
        $this->assertStringContainsString(
            "[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v1.3.0...HEAD\n"
            . '[1.3.0]: https://github.com/AM-Digital-Research-Environment/DRE-SEO/compare/v1.2.0...v1.3.0',
            $changelog
        );

        $this->assertSame(0, $this->script('--check', '1.3.0'));
        $this->assertSame(1, $this->script('--check', '1.2.0'), 'the check names a version the files no longer state');
    }

    public function testItRefusesAnEmptyUnreleasedSectionOrAVersionNotAboveTheCurrentOne(): void
    {
        $this->assertSame(1, $this->script('1.2.0'));
        $this->assertSame(1, $this->script('1.1.9'));
        $this->assertStringContainsString('version           = "1.2.0"', $this->read('config/module.ini'), 'a refusal writes nothing');

        $this->assertSame(0, $this->script('1.3.0'));
        $this->assertSame(1, $this->script('1.4.0'), 'nothing left under Unreleased');
    }

    public function testTheRepositoryAgreesWithItself(): void
    {
        $ini = (string) file_get_contents(dirname(__DIR__, 2) . '/config/module.ini');
        $this->assertSame(1, preg_match('/^version\s*=\s*"([^"]+)"/m', $ini, $m));
        $this->assertSame(0, $this->script('--check', $m[1], '--root=' . dirname(__DIR__, 2)));
    }

    private function script(string ...$args): int
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/.github/scripts/bump-version.php'], $args);
        if (!array_filter($args, static fn (string $arg): bool => str_starts_with($arg, '--root='))) {
            $command[] = '--root=' . $this->root;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process);
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->root . '/' . $file);
    }
}
