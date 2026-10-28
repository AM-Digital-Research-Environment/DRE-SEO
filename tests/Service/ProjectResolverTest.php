<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\ProjectResolver;
use Omeka\Api\Representation\ItemRepresentation as Item;
use Omeka\Api\Representation\ItemSetRepresentation as ItemSet;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;
use PHPUnit\Framework\TestCase;

/** A record's research project, and the project item-set redirect. */
final class ProjectResolverTest extends TestCase
{
    private static function projectItem(int $id = 100, bool $public = true, string $class = 'foaf:Project'): Item
    {
        $project = new Item('Project ' . $id, 5, null, [], 'https://example.test/s/amira/item/' . $id, $id, $class);
        $project->publicValue = $public;
        return $project;
    }

    private static function projectSet(?Item $partOf = null, ?string $dreId = null, int $id = 6259): ItemSet
    {
        $values = [];
        if ($partOf !== null) {
            $values['dcterms:isPartOf'] = [new Value(resource: $partOf)];
        }
        if ($dreId !== null) {
            $values['dre:id'] = [new Value($dreId)];
        }
        return new ItemSet('Collection ' . $id, values: $values, publicUrl: 'https://example.test/s/amira/item-set/' . $id, idValue: $id);
    }

    public function testAProjectItemSetPageRedirectsToTheProjectForGETAndHEAD(): void
    {
        $resolver = new ProjectResolver();
        $site = new SiteRepresentation();
        $set = self::projectSet(self::projectItem());

        $this->assertSame('https://example.test/s/amira/item/100', $resolver->itemSetRedirectUrl('GET', 'site/item-set', $set, $site));
        $this->assertSame('https://example.test/s/amira/item/100', $resolver->itemSetRedirectUrl('head', 'site/item-set', $set, $site));
    }

    public function testNothingElseRedirects(): void
    {
        $resolver = new ProjectResolver();
        $site = new SiteRepresentation();
        $set = self::projectSet(self::projectItem());

        $this->assertSame(null, $resolver->itemSetRedirectUrl('POST', 'site/item-set', $set, $site), 'a POST');
        $this->assertSame(null, $resolver->itemSetRedirectUrl('GET', 'site/resource', $set, $site), '/item?item_set_id= (the browse route)');
        $this->assertSame(null, $resolver->itemSetRedirectUrl('GET', 'admin/id', $set, $site), 'the admin');
        $this->assertSame(null, $resolver->itemSetRedirectUrl('GET', 'site/item-set', null, $site), 'an item set that cannot be read');
        $this->assertSame(null, $resolver->itemSetRedirectUrl('GET', 'site/item-set', self::projectSet(), $site), 'an item set with no project');
        $this->assertSame(
            null,
            $resolver->itemSetRedirectUrl('GET', 'site/item-set', self::projectSet(self::projectItem(101, false), id: 6260), $site),
            'a private project'
        );
        $this->assertSame(
            null,
            $resolver->itemSetRedirectUrl('GET', 'site/item-set', self::projectSet(self::projectItem(102, true, 'frapo:ResearchGroup'), id: 6261), $site),
            'an item set part of a research section, not a project'
        );
    }

    public function testASiteCanKeepItsProjectItemSetPages(): void
    {
        $settings = [1 => '0', 2 => '1', 3 => null];
        $resolver = new ProjectResolver(null, static fn (int $siteId): mixed => $settings[$siteId] ?? null);
        $set = self::projectSet(self::projectItem());

        $this->assertSame(null, $resolver->itemSetRedirectUrl('GET', 'site/item-set', $set, new SiteRepresentation(idValue: 1)), 'switched off');
        $this->assertTrue($resolver->redirectsEnabled(2), 'switched on');
        $this->assertTrue($resolver->redirectsEnabled(3), 'on until set');
        // The stub builds a page URL from the site slug when it has no fixed one.
        $elsewhere = self::projectSet(new Item('Project', 5, null, [], null, 1, 'foaf:Project'), id: 6264);
        $this->assertSame(
            'https://example.test/s/other/item/1',
            $resolver->itemSetRedirectUrl('GET', 'site/item-set', $elsewhere, new SiteRepresentation('other', idValue: 3)),
            'redirected within the same site'
        );
    }

    public function testAnItemSetWithoutTheProjectLinkFallsBackToTheProjectSharingItsDreId(): void
    {
        $asked = [];
        $resolver = new ProjectResolver(static function (string $dreId) use (&$asked): array {
            $asked[] = $dreId;
            return [
                new Item('A research item', 10, null, [], null, 4001, null),
                self::projectItem(101, false),
                self::projectItem(102),
            ];
        });
        $site = new SiteRepresentation();

        $set = self::projectSet(null, ' P-0042 ');
        $this->assertSame('https://example.test/s/amira/item/102', $resolver->itemSetRedirectUrl('GET', 'site/item-set', $set, $site));
        $this->assertSame(['P-0042'], $asked);

        // Resolved once per item set and request.
        $resolver->projectOfItemSet($set);
        $this->assertSame(1, count($asked));

        // The item set's own link wins over the dre:id, and no search is made.
        $linked = self::projectSet(self::projectItem(103), 'P-0042', 6262);
        $this->assertSame(103, $resolver->projectOfItemSet($linked)?->id());
        $this->assertSame(1, count($asked));

        // No dre:id, no search.
        $this->assertSame(null, $resolver->projectOfItemSet(self::projectSet(id: 6263)));
        $this->assertSame(1, count($asked));
    }

    public function testARecordsProjectIsItsFirstIsPartOfLinkToAPublicProject(): void
    {
        $resolver = new ProjectResolver();
        $section = self::projectItem(200, true, 'frapo:ResearchGroup');
        $private = self::projectItem(201, false);
        $project = self::projectItem(202);
        $item = new Item('Interview', 10, null, ['dcterms:isPartOf' => [
            new Value('A literal'),
            new Value(resource: $section),
            new Value(resource: $private),
            new Value(resource: $project),
        ]], null, 4001);

        $this->assertSame(202, $resolver->projectOf($item)?->id());
        $this->assertTrue(ProjectResolver::isProject($project));
        $this->assertTrue(!ProjectResolver::isProject($section));
        $this->assertTrue(!ProjectResolver::isProject(self::projectSet()), 'an item set is never the project item');

        // A project is never its own project.
        $self = self::projectItem(300);
        $selfLinked = new Item('Project 300', 5, null, ['dcterms:isPartOf' => [new Value(resource: $self)]], null, 300, 'foaf:Project');
        $this->assertSame(null, $resolver->projectOf($selfLinked));
    }
}
