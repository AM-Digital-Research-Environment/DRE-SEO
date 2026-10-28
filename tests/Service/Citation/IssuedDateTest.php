<?php
declare(strict_types=1);

namespace DRESeo\Test\Service\Citation;

use DRESeo\Service\Citation\IssuedDate;
use PHPUnit\Framework\TestCase;

/** IssuedDate: the precision a stored date carries, and what it degrades to. */
final class IssuedDateTest extends TestCase
{
    public function testAStoredTimestampKeepsWhateverPrecisionItCarries(): void
    {
        $year = IssuedDate::parse('2018');
        $this->assertSame(2018, $year->year);
        $this->assertSame(null, $year->month);

        $month = IssuedDate::parse('2018-12');
        $this->assertSame(2018, $month->year);
        $this->assertSame(12, $month->month);
        $this->assertSame(null, $month->day);

        $day = IssuedDate::parse('2018-12-07');
        $this->assertSame(12, $day->month);
        $this->assertSame(7, $day->day);
    }

    public function testAnUnparseableDateIsPreservedVerbatim(): void
    {
        $date = IssuedDate::parse('n.d.');

        $this->assertSame(null, $date->year);
        $this->assertTrue(!$date->hasYear());
        $this->assertSame('n.d.', $date->literal);
        $this->assertSame('n.d.', $date->yearOrLiteral());
    }

    public function testAnEmptyDateIsUnknown(): void
    {
        $date = IssuedDate::parse('   ');

        $this->assertSame(null, $date->year);
        $this->assertSame(null, $date->literal);
        $this->assertSame(null, $date->yearOrLiteral());
    }
}
