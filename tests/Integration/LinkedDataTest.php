<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\LinkedDataIdentityFactory;
use DRESeo\Service\Signposting;
use Laminas\Http\Header\GenericMultiHeader;
use Laminas\Http\Header\MultipleHeaderInterface;
use Laminas\Http\Response;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\HelperPluginManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * DRE Linked Data is optional: without its helper there is no identity; with
 * it, the helper is reached through the view helper manager. The Signposting
 * Link header built from that identity must pass Laminas' header validation.
 */
final class LinkedDataTest extends OmekaTestCase
{
    public function testWithoutDreLinkedDataNoResourceUriIsClaimed(): void
    {
        $item = self::withoutConstructor(ItemRepresentation::class);

        $this->assertNull(
            $this->services->get(LinkedDataIdentity::class)->uri($item),
            'A resource URI was claimed without DRE Linked Data.'
        );
    }

    public function testTheDreLinkedDataHelperIsReachedThroughTheViewHelperManager(): void
    {
        $item = self::withoutConstructor(ItemRepresentation::class);
        $withLinkedData = new ServiceManager();
        $withLinkedData->setService('ViewHelperManager', new HelperPluginManager($withLinkedData, [
            'factories' => [
                'dreLinkedData' => static fn () => static fn ($resource): array => [
                    'uri' => 'https://example.test/resource/7',
                    'downloads' => [['label' => 'Turtle', 'type' => 'text/turtle', 'url' => 'https://example.test/resource/7.ttl']],
                ],
            ],
        ]));
        $identity = (new LinkedDataIdentityFactory())($withLinkedData, LinkedDataIdentity::class);

        $message = 'The dreLinkedData helper was not reached through the view helper manager.';
        $this->assertSame('https://example.test/resource/7', $identity->uri($item), $message);
        $this->assertCount(1, $identity->documents($item), $message);
    }

    public function testTheLinkHeaderPassesLaminasValidationAsAMultiHeader(): void
    {
        // Sent as a multi-header, so another module's Link header survives.
        $linkHeader = new GenericMultiHeader('Link', Signposting::header([
            ['rel' => 'cite-as', 'href' => 'https://example.test/s/amira/item/7'],
            ['rel' => 'item', 'href' => 'https://example.test/files/original/Ü 1.pdf', 'type' => 'application/pdf'],
        ]));
        $response = new Response();
        $response->getHeaders()->addHeader($linkHeader);
        $sentHeaders = $response->getHeaders()->toString();

        $this->assertStringContainsString(
            'Link: <https://example.test/s/amira/item/7>; rel="cite-as", '
                . '<https://example.test/files/original/%C3%9C%201.pdf>; rel="item"; type="application/pdf"',
            $sentHeaders,
            "The Signposting Link header did not serialise:\n" . $sentHeaders
        );
        $this->assertInstanceOf(
            MultipleHeaderInterface::class,
            $linkHeader,
            'The Link header would replace other Link headers.'
        );
    }
}
