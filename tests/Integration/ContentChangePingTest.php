<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Service\DefaultSite;
use DRESeo\Test\Integration\Double\FixedSite;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Omeka\Api\Request;
use Omeka\Api\Response;
use ReflectionProperty;

/**
 * IndexNow from the API events Omeka triggers. The api.*.post events carry
 * the entity, not its representation; a public record, one just made private
 * and one about to be deleted are queued at their page URL on the default
 * site, and a record private before and after is not.
 */
final class ContentChangePingTest extends OmekaTestCase
{
    public function testChangedRecordsAreQueuedAtTheirPageUrls(): void
    {
        $defaultSite = $this->services->get(DefaultSite::class);
        foreach (['site' => new FixedSite(), 'resolved' => true] as $property => $value) {
            (new ReflectionProperty(DefaultSite::class, $property))->setValue($defaultSite, $value);
        }
        $this->settings->set('dre_seo_ping_enabled', '1');
        $this->settings->set('dre_seo_indexnow_key', 'abcdef1234567890');

        $entity = static fn (int $id, bool $public): object => new class ($id, $public) {
            public function __construct(private int $id, public bool $public)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function isPublic(): bool
            {
                return $this->public;
            }
        };
        $records = [7 => $entity(7, true), 8 => $entity(8, false), 9 => $entity(9, false), 10 => $entity(10, true)];
        $adapter = new class ($records) {
            /** @param array<int,object> $records */
            public function __construct(private array $records)
            {
            }

            public function getRepresentation(object $entity): object
            {
                return new class ($entity->getId()) {
                    public function __construct(private int $id)
                    {
                    }

                    /**
                     * @param string|null $slug
                     * @param bool $canonical
                     */
                    public function siteUrl($slug = null, $canonical = false): string
                    {
                        return 'https://example.test/s/' . $slug . '/item/' . $this->id;
                    }
                };
            }

            /**
             * @param int $id
             * @param Request|null $request
             */
            public function findEntity($id, $request = null): object
            {
                return $this->records[$id];
            }
        };
        $apiEvents = new EventManager($this->sharedEvents, ['Omeka\\Api\\Adapter\\ItemAdapter']);
        $update = new Request(Request::UPDATE, 'items');

        // 7: public, edited. 8: public until this update made it private.
        // 9: private before and after. 10: public, deleted.
        $records[8]->public = true;
        $apiEvents->triggerEvent(new Event('api.hydrate.pre', $adapter, ['entity' => $records[8], 'request' => $update]));
        $records[8]->public = false;
        $apiEvents->triggerEvent(new Event('api.hydrate.pre', $adapter, ['entity' => $records[9], 'request' => $update]));
        foreach ([7, 8, 9] as $id) {
            $apiEvents->triggerEvent(new Event('api.update.post', $adapter, [
                'request' => $update,
                'response' => new Response($records[$id]),
            ]));
        }
        $delete = new Request(Request::DELETE, 'items');
        $delete->setId(10);
        $apiEvents->triggerEvent(new Event('api.delete.pre', $adapter, ['request' => $delete]));

        $this->assertSame(
            [
                'https://example.test/s/amira/item/7',
                'https://example.test/s/amira/item/8',
                'https://example.test/s/amira/item/10',
            ],
            $this->settings->get('dre_seo_ping_pending'),
            'IndexNow did not queue the changed records: ' . json_encode($this->settings->get('dre_seo_ping_pending'))
        );
    }
}
