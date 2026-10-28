<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use Closure;
use DRESeo\Service\LandingPages;
use DRESeo\Service\ProjectResolver;
use DRESeo\Service\StructuredData;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ItemRepresentation as Item;
use Omeka\Api\Representation\ItemSetRepresentation as ItemSet;
use Omeka\Api\Representation\MediaRepresentation as Media;
use Omeka\Api\Representation\SitePageRepresentation as Page;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;
use PHPUnit\Framework\TestCase;

/**
 * The BreadcrumbList trail StructuredData builds for a record: through its
 * project and the landing page of its item set, over a miniature of AMIRA's
 * pages and item sets, and the site setting that maps item sets to pages.
 */
final class BreadcrumbTrailTest extends TestCase
{
    /**
     * AMIRA in miniature: the home page, the Research projects and Publications
     * landing pages (and a private People page), the Projects and Publications
     * item sets, one project item and its collection item set.
     *
     * @return array{site:SiteRepresentation,projects:ItemSet,publications:ItemSet,project:Item,collection:ItemSet}
     */
    private static function amiraWorld(): array
    {
        $site = new SiteRepresentation('amira', 'AMIRA', new Page());
        $site->pagesList = [
            new Page(),
            new Page('research-projects', 'Research projects', 11),
            new Page('publications', 'Publications', 12),
            new Page('people', 'People', 13, false),
        ];
        $site->siteItemSetIds = [20, 29918, 6259];

        $projects = new ItemSet('Projects', publicUrl: 'https://example.test/s/amira/item-set/20', idValue: 20);
        $publications = new ItemSet('Publications', publicUrl: 'https://example.test/s/amira/item-set/29918', idValue: 29918);
        $project = new Item('Lagos Talks', 5, null, [], 'https://example.test/s/amira/item/100', 100, 'foaf:Project');
        $project->itemSetList = [20 => $projects];
        $collection = new ItemSet(
            'Lagos Talks collection',
            values: ['dcterms:isPartOf' => [new Value(resource: $project)]],
            publicUrl: 'https://example.test/s/amira/item-set/6259',
            idValue: 6259
        );

        return compact('site', 'projects', 'publications', 'project', 'collection');
    }

    /** @param array<int,string> $map */
    private static function trailBuilder(array $map = [20 => 'research-projects', 29918 => 'publications', 18 => 'people'], ?Closure $redirect = null): StructuredData
    {
        return new StructuredData(
            [],
            'CreativeWork',
            null,
            null,
            [],
            new LandingPages($map),
            new ProjectResolver(null, $redirect),
        );
    }

    /** @return string[] the names along a record's trail */
    private static function trailNames(StructuredData $builder, object $resource, SiteRepresentation $site): array
    {
        return array_column($builder->trail(new PhpRenderer(), $resource, $site, $resource->siteUrl('amira', true)) ?? [], 'name');
    }

    public function testAResearchItemIsPlacedUnderItsProjectAndTheProjectUnderItsLandingPage(): void
    {
        ['site' => $site, 'project' => $project, 'collection' => $collection] = self::amiraWorld();
        $interview = new Item(
            'Interview 3',
            10,
            null,
            ['dcterms:isPartOf' => [new Value(resource: $project)]],
            'https://example.test/s/amira/item/4001',
            4001
        );
        $interview->itemSetList = [6259 => $collection];
        $builder = self::trailBuilder();
        $canonical = 'https://example.test/s/amira/item/4001';

        $trail = $builder->trail(new PhpRenderer(), $interview, $site, $canonical);
        $this->assertSame([
            ['name' => 'AMIRA', 'url' => 'https://example.test/s/amira/page/home'],
            ['name' => 'Research projects', 'url' => 'https://example.test/s/amira/page/research-projects'],
            ['name' => 'Lagos Talks', 'url' => 'https://example.test/s/amira/item/100'],
            ['name' => 'Interview 3', 'url' => $canonical],
        ], $trail);

        // The BreadcrumbList states the same trail.
        $list = $builder->breadcrumb(new PhpRenderer(), $interview, $site, $canonical);
        $this->assertSame(array_column($trail, 'url'), array_column($list['itemListElement'], 'item'));
        $this->assertSame([1, 2, 3, 4], array_column($list['itemListElement'], 'position'));

        // A file of the research item: the item's trail, then the item.
        $scan = new Media('Scan 1', publicUrl: 'https://example.test/s/amira/media/5', idValue: 5);
        $scan->parent = $interview;
        $this->assertSame(
            ['AMIRA', 'Research projects', 'Lagos Talks', 'Interview 3', 'Scan 1'],
            self::trailNames($builder, $scan, $site)
        );
    }

    public function testAProjectAndOtherItemsArePlacedUnderTheLandingPageOfTheirItemSet(): void
    {
        ['site' => $site, 'project' => $project, 'publications' => $publications] = self::amiraWorld();
        $builder = self::trailBuilder();

        $this->assertSame(['AMIRA', 'Research projects', 'Lagos Talks'], self::trailNames($builder, $project, $site));
        $this->assertSame(
            'https://example.test/s/amira/page/research-projects',
            $builder->trail(new PhpRenderer(), $project, $site, 'https://example.test/s/amira/item/100')[1]['url']
        );

        // An item in a set the site does not use and in Publications: the
        // landing page of the first set that has one.
        $thesis = new Item('Das Archiv als Heimat', 16, publicUrl: 'https://example.test/s/amira/item/41980', idValue: 41980);
        $thesis->itemSetList = [7 => new ItemSet('Unused', idValue: 7), 29918 => $publications];
        $this->assertSame(['AMIRA', 'Publications', 'Das Archiv als Heimat'], self::trailNames($builder, $thesis, $site));
    }

    public function testWithoutAPublicLandingPageTheItemSetIsNamedAsBefore(): void
    {
        ['site' => $site, 'project' => $project] = self::amiraWorld();

        // No map at all: the item set.
        $bare = new StructuredData([]);
        $this->assertSame(['AMIRA', 'Projects', 'Lagos Talks'], self::trailNames($bare, $project, $site));

        // The mapped page does not exist in the site.
        $missing = self::trailBuilder([20 => 'no-such-page']);
        $this->assertSame(['AMIRA', 'Projects', 'Lagos Talks'], self::trailNames($missing, $project, $site));

        // The mapped page is private.
        $person = new Item('Aas, Norbert', 4, publicUrl: 'https://example.test/s/amira/item/7', idValue: 7);
        $person->itemSetList = [18 => new ItemSet('Persons', publicUrl: 'https://example.test/s/amira/item-set/18', idValue: 18)];
        $this->assertSame(['AMIRA', 'Persons', 'Aas, Norbert'], self::trailNames(self::trailBuilder(), $person, $site));

        // A research item whose project became private: placed in its item set.
        $project->publicValue = false;
        $interview = new Item('Interview 3', 10, null, ['dcterms:isPartOf' => [new Value(resource: $project)]], 'https://example.test/s/amira/item/4001', 4001);
        $interview->itemSetList = [7 => new ItemSet('Unsorted', publicUrl: 'https://example.test/s/amira/item-set/7', idValue: 7)];
        $this->assertSame(['AMIRA', 'Unsorted', 'Interview 3'], self::trailNames(self::trailBuilder(), $interview, $site));
    }

    public function testAnItemSetWhosePageRedirectsIsNamedByItsProject(): void
    {
        ['site' => $site, 'collection' => $collection] = self::amiraWorld();
        // An item of the collection that does not name the project itself.
        $photo = new Item('Photo 12', 10, publicUrl: 'https://example.test/s/amira/item/4002', idValue: 4002);
        $photo->itemSetList = [6259 => $collection];

        $this->assertSame(['AMIRA', 'Research projects', 'Lagos Talks', 'Photo 12'], self::trailNames(self::trailBuilder(), $photo, $site));

        // The site keeps its item-set pages: the item set is named.
        $kept = self::trailBuilder(redirect: static fn (int $siteId): string => '0');
        $this->assertSame(['AMIRA', 'Lagos Talks collection', 'Photo 12'], self::trailNames($kept, $photo, $site));
    }

    public function testAnItemSetPageIsPlacedUnderItsLandingPage(): void
    {
        ['site' => $site, 'publications' => $publications, 'collection' => $collection] = self::amiraWorld();
        $builder = self::trailBuilder();

        $this->assertSame([
            ['name' => 'AMIRA', 'url' => 'https://example.test/s/amira/page/home'],
            ['name' => 'Publications', 'url' => 'https://example.test/s/amira/page/publications'],
            ['name' => 'Publications', 'url' => 'https://example.test/s/amira/item-set/29918'],
        ], $builder->trail(new PhpRenderer(), $publications, $site, 'https://example.test/s/amira/item-set/29918'));

        // No landing page: Home › the item set.
        $this->assertSame(['AMIRA', 'Lagos Talks collection'], self::trailNames($builder, $collection, $site));
    }

    public function testASiteSettingOverridesTheLandingPagesLineByLine(): void
    {
        ['site' => $site, 'project' => $project, 'publications' => $publications] = self::amiraWorld();
        $site->pagesList[] = new Page('research', 'Research', 14);
        $landing = new LandingPages(
            [20 => 'research-projects', 29918 => 'publications', 41268 => ''],
            static fn (int $siteId): string => "# AMIRA\n20 = research\n\n29918 =\n"
        );

        $this->assertSame([20 => 'research'], $landing->map($site));
        $this->assertSame(['name' => 'Research', 'url' => 'https://example.test/s/amira/page/research'], $landing->forItemSets([$project->itemSets()[20]], $site));
        $this->assertSame(null, $landing->forItemSets([$publications], $site), '"29918 =" drops the default');

        // No setting: the defaults, less the sets mapped to nothing.
        $this->assertSame([20 => 'research-projects', 29918 => 'publications'], (new LandingPages([20 => 'research-projects', 29918 => 'publications', 41268 => '']))->map($site));
    }

    public function testTheLandingPageSettingIsParsedLenientlyAndValidatedStrictly(): void
    {
        $this->assertSame([20 => 'research-projects', 18 => ''], LandingPages::parse("20=research-projects\r\n  18 =  \n# 17 = sections\nnonsense\n"));
        $this->assertSame(['nonsense', '20 = two words'], LandingPages::invalidLines("20 = ok\nnonsense\n# comment\n\n20 = two words"));
        $this->assertSame([], LandingPages::invalidLines(''));
        $this->assertSame("20 = research-projects\n41268 =", LandingPages::format([20 => 'research-projects', 41268 => '']));
        // The shipped defaults are valid setting lines.
        $config = require __DIR__ . '/../../config/module.config.php';
        $this->assertSame([], LandingPages::invalidLines(LandingPages::format($config['dre_seo']['breadcrumb']['landing_pages'])));
    }
}
