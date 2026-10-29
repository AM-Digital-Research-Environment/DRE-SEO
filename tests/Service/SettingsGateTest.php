<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\SettingsGate;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * "What counts as on" used to be defined in the static SettingFlag helper and
 * in private copies beside it. The config form stores a checkbox as '1' or
 * '0', a script or an older release as an int or a bool: every accepted form
 * is pinned here, as are the text and integer reads the services rely on.
 */
final class SettingsGateTest extends TestCase
{
    /** @param array<string,mixed> $values */
    private function gate(array $values): SettingsGate
    {
        $settings = new Settings();
        foreach ($values as $key => $value) {
            $settings->set($key, $value);
        }
        return new SettingsGate($settings);
    }

    public function testTruthinessAcceptsEveryStoredForm(): void
    {
        $gate = $this->gate(['s' => '1', 'i' => 1, 'b' => true]);
        $this->assertTrue($gate->isOn('s'));
        $this->assertTrue($gate->isOn('i'));
        $this->assertTrue($gate->isOn('b'));
    }

    public function testFalsinessRejectsEverythingElse(): void
    {
        $gate = $this->gate(['zero' => '0', 'int' => 0, 'no' => false, 'empty' => '', 'text' => 'yes', 'two' => 2]);
        foreach (['zero', 'int', 'no', 'empty', 'text', 'two'] as $key) {
            $this->assertFalse($gate->isOn($key), $key);
        }
    }

    public function testDefaultAppliesOnlyWhenUnset(): void
    {
        $this->assertTrue($this->gate([])->isOn('missing', true));
        $this->assertFalse($this->gate([])->isOn('missing'));
        // An explicit '0' must beat a true default: it is the kill switch.
        $this->assertFalse($this->gate(['k' => '0'])->isOn('k', true));
    }

    public function testTextTrimsAndRawDoesNot(): void
    {
        $gate = $this->gate(['k' => "  token \n"]);
        $this->assertSame('token', $gate->text('k'));
        $this->assertSame("  token \n", $gate->raw('k'));
        $this->assertSame('', $gate->text('missing'));
        $this->assertSame('', $gate->raw('missing'));
    }

    /** The sitemap lifetime as the controller has always read it: a blank field is 0, not the default. */
    public function testIntFallsBackOnlyWhenUnset(): void
    {
        $gate = $this->gate(['ttl' => '3600', 'blank' => '', 'zero' => 0]);
        $this->assertSame(3600, $gate->int('ttl'));
        $this->assertSame(86400, $gate->int('missing', 86400));
        $this->assertSame(0, $gate->int('blank', 86400));
        $this->assertSame(0, $gate->int('zero', 86400));
        $this->assertSame(0, $gate->int('missing'));
    }

    public function testSetWritesThrough(): void
    {
        $settings = new Settings();
        (new SettingsGate($settings))->set('dre_seo_ping_last', 1234);
        $this->assertSame(1234, $settings->get('dre_seo_ping_last'));
    }
}
