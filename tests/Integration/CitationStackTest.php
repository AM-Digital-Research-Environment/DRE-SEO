<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\CitationExport;
use DRESeo\Service\CitationFormatter;
use DRESeo\Service\CitationKind;

/**
 * The citation stack, end to end through the services the factories build: a
 * formatted citation and every offered download serialisation.
 */
final class CitationStackTest extends OmekaTestCase
{
    private static function record(): CitationRecord
    {
        return new CitationRecord(
            id: 42,
            kind: CitationKind::Article,
            title: 'Islamic Reform in Colonial Burkina Faso',
            authors: [Creator::parse('Frédérick Madore', false)],
            issued: IssuedDate::parse('2021-05-16'),
            container: 'Journal of African History',
            doi: '10.1017/S0021853721000372',
            url: 'https://example.test/s/amira/item/42',
        );
    }

    public function testTheChicagoCitationRendersItsSegments(): void
    {
        $citationHtml = $this->services->get(CitationFormatter::class)->format(self::record(), 'chicago');

        $message = 'Chicago citation did not render the expected segments: ' . $citationHtml;
        $this->assertStringContainsString('Madore, Frédérick.', $citationHtml, $message);
        $this->assertStringContainsString('<em>Journal of African History</em>', $citationHtml, $message);
        $this->assertStringContainsString('https://doi.org/10.1017/S0021853721000372', $citationHtml, $message);
    }

    public function testEveryOfferedDownloadFormatSerialises(): void
    {
        $export = $this->services->get(CitationExport::class);
        foreach (array_keys(CitationExport::FORMATS) as $format) {
            $serialised = $export->serialize(self::record(), $format);
            $this->assertIsString($serialised, sprintf('Citation export produced nothing for %s.', $format));
            $this->assertNotSame('', $serialised, sprintf('Citation export produced nothing for %s.', $format));
        }
    }
}
