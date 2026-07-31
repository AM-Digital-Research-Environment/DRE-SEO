<?php
declare(strict_types=1);

use DRESeo\Service\StructuredData;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation as Resource;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;

require_once __DIR__ . '/../src/Service/StructuredData.php';

test('YouTube resources emit current VideoObject fields', function (): void {
    $speaker = new Resource('Ada Example');
    $language = new Resource('English');
    $playlist = new Resource(
        'Research stories',
        publicUrl: 'https://example.test/s/amira/item/99'
    );
    $video = new Resource(
        'A research video',
        22,
        'Audio Visual Document',
        [
            'dcterms:abstract' => [new Value('A concise video description.')],
            'dcterms:date' => [new Value('2026-07-20')],
            'marcrel:spk' => [new Value(resource: $speaker)],
            'dcterms:language' => [new Value(resource: $language)],
            'dcterms:isPartOf' => [new Value(resource: $playlist)],
            'fabio:hasURL' => [new Value(uriValue: 'https://www.youtube.com/watch?v=abc123')],
        ]
    );
    $builder = new StructuredData([22 => 'VideoObject']);

    $data = $builder->forResource(
        $video,
        new SiteRepresentation(),
        'https://example.test/s/amira/item/22',
        'https://i.ytimg.com/vi/abc123/hqdefault.jpg'
    );

    assertSameValue('VideoObject', $data['@type']);
    assertSameValue('2026-07-20', $data['uploadDate']);
    assertSameValue('https://i.ytimg.com/vi/abc123/hqdefault.jpg', $data['thumbnailUrl']);
    assertSameValue(['https://www.youtube.com/watch?v=abc123'], $data['sameAs']);
    assertSameValue('Ada Example', $data['author'][0]['name']);
    assertSameValue('English', $data['inLanguage']);
    assertSameValue('Research stories', $data['isPartOf']['name']);
});
