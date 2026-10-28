<?php
declare(strict_types=1);

namespace DRESeo\Test\Service\Citation;

use DRESeo\Service\Citation\Creator;
use PHPUnit\Framework\TestCase;

/** Creator: how a displayed name is split into family and given parts. */
final class CreatorTest extends TestCase
{
    public function testAPersonNameDisplayedFirstLastIsSplitOnTheLastToken(): void
    {
        $creator = Creator::parse('Frédérick Madore', false);

        $this->assertSame('Madore', $creator->family);
        $this->assertSame('Frédérick', $creator->given);
        $this->assertSame('Frédérick Madore', $creator->literal);
        $this->assertTrue(!$creator->isInstitution);
        $this->assertTrue(!$creator->isSingleField());
    }

    public function testAnAlreadyInvertedNameIsDetectedByItsComma(): void
    {
        $creator = Creator::parse('Madore, Frédérick', false);

        $this->assertSame('Madore', $creator->family);
        $this->assertSame('Frédérick', $creator->given);
    }

    public function testAMultiWordGivenNameKeepsEveryToken(): void
    {
        $creator = Creator::parse('Maria Elena Rodrigues', false);

        $this->assertSame('Rodrigues', $creator->family);
        $this->assertSame('Maria Elena', $creator->given);
    }

    public function testAnInstitutionIsNeverSplitOrInverted(): void
    {
        $creator = Creator::parse('Africa Multiple Cluster of Excellence', true);

        $this->assertSame(null, $creator->family);
        $this->assertSame(null, $creator->given);
        $this->assertSame('Africa Multiple Cluster of Excellence', $creator->literal);
        $this->assertTrue($creator->isSingleField());
    }

    public function testASingleTokenNameBecomesAFamilyNameWithNoGivenPart(): void
    {
        $creator = Creator::parse('Plato', false);

        $this->assertSame('Plato', $creator->family);
        $this->assertSame(null, $creator->given);
        // It still has a family name, so it is not an unsplittable single field —
        // that is reserved for institutions and names with no family part at all.
        $this->assertTrue(!$creator->isSingleField());
    }

    public function testACreatorSurvivesARoundTripThroughItsArrayForm(): void
    {
        foreach ([Creator::parse('Frédérick Madore', false), Creator::parse('DFG', true)] as $creator) {
            $back = Creator::fromArray($creator->toArray());
            $this->assertSame($creator->family, $back->family);
            $this->assertSame($creator->given, $back->given);
            $this->assertSame($creator->literal, $back->literal);
            $this->assertSame($creator->isInstitution, $back->isInstitution);
        }
    }
}
