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

    public function testAMonthOrDayThatCannotExistIsDroppedNotEmitted(): void
    {
        $span = IssuedDate::parse('1999-20');
        $this->assertSame(1999, $span->year);
        $this->assertSame(null, $span->month, 'no month 20');

        $february = IssuedDate::parse('2018-02-30');
        $this->assertSame(2, $february->month);
        $this->assertSame(null, $february->day, 'no 30 February');
        $this->assertSame('2018-02', $february->iso());

        $this->assertSame(null, IssuedDate::parse('12345')->year, 'five digits are no year');
    }

    public function testAnIntervalKeepsBothEndsAndCitesItsYears(): void
    {
        $date = IssuedDate::parse('1950-01-01/1962-01-01');

        $this->assertTrue($date->isInterval());
        $this->assertSame(1950, $date->year);
        $this->assertSame(1962, $date->until?->year);
        $this->assertSame('1950–1962', $date->yearOrLiteral());
        $this->assertSame([[1950, 1, 1], [1962, 1, 1]], $date->dateParts());
        $this->assertSame('1950-01-01/1962-01-01', $date->isoRange());
    }

    public function testASingleDateFormatGetsOnlyWhatTheTwoEndsShare(): void
    {
        $years = IssuedDate::parse('1950-01-01/1962-01-01')->asSingleDate();
        $this->assertSame('1950', $years->iso(), 'not 1 January 1950');
        $this->assertSame('1950', $years->slashed());

        $months = IssuedDate::parse('2020-07-01/2020-12-31')->asSingleDate();
        $this->assertSame('2020', $months->iso());

        $days = IssuedDate::parse('2020-07-01/2020-07-31')->asSingleDate();
        $this->assertSame('2020-07', $days->iso());

        $plain = IssuedDate::parse('2018-12-07');
        $this->assertSame($plain, $plain->asSingleDate(), 'a date that is no interval stays itself');
    }

    public function testASpanOfYearsTypedAsTextIsAnInterval(): void
    {
        $date = IssuedDate::parse('1999-2001');

        $this->assertTrue($date->isInterval());
        $this->assertSame('1999–2001', $date->yearOrLiteral());
        $this->assertSame([[1999], [2001]], $date->dateParts());
    }

    public function testAnIntervalSurvivesTheArrayRoundTrip(): void
    {
        $date = IssuedDate::parse('1950-01-01/1962-01-01');

        $this->assertSame($date->toArray(), IssuedDate::fromArray($date->toArray())->toArray());
    }
}
