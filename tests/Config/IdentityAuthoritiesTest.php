<?php
declare(strict_types=1);

namespace DRESeo\Test\Config;

use DRESeo\Service\IdentityAuthorities;
use DRESeo\Service\Signposting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The identity-link rules are DRE Linked Data's: config/identity-authorities.php
 * is a byte-identical copy of that module's table, and tests/Fixture/
 * identity-links.json the cases both modules run through their own code. The
 * hashes pinned here are pinned in DRE Linked Data's tests/ConfigurationTest.php
 * too, so neither copy can change alone.
 */
final class IdentityAuthoritiesTest extends TestCase
{
    private const TABLE = __DIR__ . '/../../config/identity-authorities.php';
    private const CASES = __DIR__ . '/../Fixture/identity-links.json';

    public function testTheTableAndTheCasesAreDreLinkedDatasByteForByte(): void
    {
        $this->assertSame(
            'e29e37c8381076a8deaa14aac62d25acdc99b2753c97b7c6ed7c6581974ecb5f',
            hash_file('sha256', self::TABLE),
            'config/identity-authorities.php must be DRE Linked Data\'s copy'
        );
        $this->assertSame(
            'e17eec50b0c358fb9fd4ab5a3d334077ea3e77960d66e9d0acbc16eca0c6300f',
            hash_file('sha256', self::CASES),
            'tests/Fixture/identity-links.json must be DRE Linked Data\'s copy'
        );
    }

    public function testEveryAuthorityCarriesWhatBothModulesRead(): void
    {
        $table = require self::TABLE;
        $this->assertSame(
            ['wikidata', 'loc', 'gnd', 'viaf', 'orcid', 'isni', 'ror', 'geonames'],
            array_keys($table)
        );
        foreach ($table as $name => $authority) {
            $this->assertNotFalse(@preg_match($authority['pattern'], ''), $name . ' pattern must compile');
            foreach (['label', 'canonical', 'dataset', 'uri_space'] as $key) {
                $this->assertNotSame('', $authority[$key] ?? '', $name . ' needs ' . $key);
            }
        }
    }

    #[DataProvider('identifierCases')]
    public function testAnIdentifierUriBecomesItsCanonicalIriOrNothing(string $input, ?string $expected): void
    {
        $this->assertSame($expected, IdentityAuthorities::authorityIri($input));
    }

    #[DataProvider('doiCases')]
    public function testADoiBecomesItsResolverIriOrNothing(string $input, ?string $expected): void
    {
        $this->assertSame($expected, IdentityAuthorities::doiIri($input));
    }

    public function testEveryAuthorityHasACaseThatMatches(): void
    {
        $table = require self::TABLE;
        $matched = [];
        foreach (self::identifierCases() as [$input, $expected]) {
            foreach ($table as $name => $authority) {
                if ($expected !== null && preg_match($authority['pattern'], trim($input))) {
                    $matched[$name] = true;
                }
            }
        }
        $this->assertSame([], array_diff(array_keys($table), array_keys($matched)));
    }

    /** Signposting's author links take the ORCID out of `sameAs` by its canonical form. */
    public function testEveryCanonicalOrcidIsTheOneSignpostingReads(): void
    {
        // The reader Signposting's author links call (ResourceValueReader::orcidOf()).
        $orcidOf = new \ReflectionMethod(Signposting::class, 'orcidOf');
        $orcids = 0;
        foreach (self::identifierCases() as [$input, $expected]) {
            if ($expected !== null && str_contains($expected, 'orcid.org')) {
                $this->assertSame($expected, $orcidOf->invoke(null, ['http://www.wikidata.org/entity/Q1', $expected]), $input);
                $orcids++;
            }
        }
        $this->assertGreaterThan(3, $orcids);
    }

    /** @return list<array{0:string,1:?string}> */
    public static function identifierCases(): array
    {
        return self::cases('identifier');
    }

    /** @return list<array{0:string,1:?string}> */
    public static function doiCases(): array
    {
        return self::cases('doi');
    }

    /** @return list<array{0:string,1:?string}> */
    private static function cases(string $kind): array
    {
        $cases = json_decode((string) file_get_contents(self::CASES), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($cases[$kind] as $case) {
            $out[] = [(string) $case['input'], $case['expected'] === null ? null : (string) $case['expected']];
        }
        return $out;
    }
}
