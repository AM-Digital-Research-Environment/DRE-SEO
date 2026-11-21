<?php
declare(strict_types=1);

namespace DRESeo\Test\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * .github/scripts/check-templates.php, as the scheduled workflow runs it, on a
 * saved /api/resource_templates response instead of the live site.
 */
final class CheckTemplatesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/dre-seo-templates-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testASiteThatMatchesTheFixturePasses(): void
    {
        [$code, $output] = $this->check($this->fixture());

        $this->assertSame(0, $code, $output);
    }

    /** How 33 and 34 went live unmapped: the site gained them, the fixture did not. */
    public function testATemplateTheFixtureLacksFails(): void
    {
        [$code, $output] = $this->check($this->fixture() + [35 => 'Exhibition']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Template 35 (Exhibition) is on the site but not in the fixture.', $output);
    }

    public function testARelabelledOrRemovedTemplateFails(): void
    {
        $site = $this->fixture();
        $site[22] = 'Video';
        unset($site[34]);

        [$code, $output] = $this->check($site);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Template 22 is "Video" on the site but "YouTube video" in the fixture.', $output);
        $this->assertStringContainsString('Template 34 (Cluster Chronicles) is in the fixture but no longer on the site.', $output);
    }

    public function testAnUnreadableResponseIsNotMistakenForADifference(): void
    {
        file_put_contents($this->file, '<html>Service unavailable</html>');

        $this->assertSame(2, $this->runScript()[0]);
    }

    /** @return array<int,string> */
    private function fixture(): array
    {
        $templates = [];
        foreach (require __DIR__ . '/../Fixture/amira-templates.php' as $group) {
            $templates += $group;
        }
        return $templates;
    }

    /**
     * @param array<int,string> $templates
     * @return array{int,string}
     */
    private function check(array $templates): array
    {
        $rows = [];
        foreach ($templates as $id => $label) {
            $rows[] = ['o:id' => $id, 'o:label' => $label];
        }
        file_put_contents($this->file, json_encode($rows));
        return $this->runScript();
    }

    /** @return array{int,string} */
    private function runScript(): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/.github/scripts/check-templates.php', '--from=' . $this->file];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
