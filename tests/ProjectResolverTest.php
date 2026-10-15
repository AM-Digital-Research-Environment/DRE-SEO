<?php
declare(strict_types=1);

use DRESeo\Service\ProjectResolver;
use Omeka\Api\Representation\ItemRepresentation as Item;
use Omeka\Api\Representation\ItemSetRepresentation as ItemSet;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;

require_once __DIR__ . '/../src/Service/ProjectResolver.php';

function projectItem(int $id = 100, bool $public = true, string $class = 'foaf:Project'): Item
{
    $project = new Item('Project ' . $id, 5, null, [], 'https://example.test/s/amira/item/' . $id, $id, $class);
    $project->publicValue = $public;
    return $project;
}

function projectSet(?Item $partOf = null, ?string $dreId = null, int $id = 6259): ItemSet
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

test('a project item-set page redirects to the project for GET and HEAD', function (): void {
    $resolver = new ProjectResolver();
    $site = new SiteRepresentation();
    $set = projectSet(projectItem());

    assertSameValue('https://example.test/s/amira/item/100', $resolver->itemSetRedirectUrl('GET', 'site/item-set', $set, $site));
    assertSameValue('https://example.test/s/amira/item/100', $resolver->itemSetRedirectUrl('head', 'site/item-set', $set, $site));
});

test('nothing else redirects', function (): void {
    $resolver = new ProjectResolver();
    $site = new SiteRepresentation();
    $set = projectSet(projectItem());

    assertSameValue(null, $resolver->itemSetRedirectUrl('POST', 'site/item-set', $set, $site), 'a POST');
    assertSameValue(null, $resolver->itemSetRedirectUrl('GET', 'site/resource', $set, $site), '/item?item_set_id= (the browse route)');
    assertSameValue(null, $resolver->itemSetRedirectUrl('GET', 'admin/id', $set, $site), 'the admin');
    assertSameValue(null, $resolver->itemSetRedirectUrl('GET', 'site/item-set', null, $site), 'an item set that cannot be read');
    assertSameValue(null, $resolver->itemSetRedirectUrl('GET', 'site/item-set', projectSet(), $site), 'an item set with no project');
    assertSameValue(
        null,
        $resolver->itemSetRedirectUrl('GET', 'site/item-set', projectSet(projectItem(101, false), id: 6260), $site),
        'a private project'
    );
    assertSameValue(
        null,
        $resolver->itemSetRedirectUrl('GET', 'site/item-set', projectSet(projectItem(102, true, 'frapo:ResearchGroup'), id: 6261), $site),
        'an item set part of a research section, not a project'
    );
});

test('a site can keep its project item-set pages', function (): void {
    $settings = [1 => '0', 2 => '1', 3 => null];
    $resolver = new ProjectResolver(null, static fn (int $siteId): mixed => $settings[$siteId] ?? null);
    $set = projectSet(projectItem());

    assertSameValue(null, $resolver->itemSetRedirectUrl('GET', 'site/item-set', $set, new SiteRepresentation(idValue: 1)), 'switched off');
    assertTrue($resolver->redirectsEnabled(2), 'switched on');
    assertTrue($resolver->redirectsEnabled(3), 'on until set');
    // The stub builds a page URL from the site slug when it has no fixed one.
    $elsewhere = projectSet(new Item('Project', 5, null, [], null, 1, 'foaf:Project'), id: 6264);
    assertSameValue(
        'https://example.test/s/other/item/1',
        $resolver->itemSetRedirectUrl('GET', 'site/item-set', $elsewhere, new SiteRepresentation('other', idValue: 3)),
        'redirected within the same site'
    );
});

test('an item set without the project link falls back to the project sharing its dre:id', function (): void {
    $asked = [];
    $resolver = new ProjectResolver(static function (string $dreId) use (&$asked): array {
        $asked[] = $dreId;
        return [
            new Item('A research item', 10, null, [], null, 4001, null),
            projectItem(101, false),
            projectItem(102),
        ];
    });
    $site = new SiteRepresentation();

    $set = projectSet(null, ' P-0042 ');
    assertSameValue('https://example.test/s/amira/item/102', $resolver->itemSetRedirectUrl('GET', 'site/item-set', $set, $site));
    assertSameValue(['P-0042'], $asked);

    // Resolved once per item set and request.
    $resolver->projectOfItemSet($set);
    assertSameValue(1, count($asked));

    // The item set's own link wins over the dre:id, and no search is made.
    $linked = projectSet(projectItem(103), 'P-0042', 6262);
    assertSameValue(103, $resolver->projectOfItemSet($linked)?->id());
    assertSameValue(1, count($asked));

    // No dre:id, no search.
    assertSameValue(null, $resolver->projectOfItemSet(projectSet(id: 6263)));
    assertSameValue(1, count($asked));
});

test('a record’s project is its first isPartOf link to a public project', function (): void {
    $resolver = new ProjectResolver();
    $section = projectItem(200, true, 'frapo:ResearchGroup');
    $private = projectItem(201, false);
    $project = projectItem(202);
    $item = new Item('Interview', 10, null, ['dcterms:isPartOf' => [
        new Value('A literal'),
        new Value(resource: $section),
        new Value(resource: $private),
        new Value(resource: $project),
    ]], null, 4001);

    assertSameValue(202, $resolver->projectOf($item)?->id());
    assertTrue(ProjectResolver::isProject($project));
    assertTrue(!ProjectResolver::isProject($section));
    assertTrue(!ProjectResolver::isProject(projectSet()), 'an item set is never the project item');

    // A project is never its own project.
    $self = projectItem(300);
    $selfLinked = new Item('Project 300', 5, null, ['dcterms:isPartOf' => [new Value(resource: $self)]], null, 300, 'foaf:Project');
    assertSameValue(null, $resolver->projectOf($selfLinked));
});
