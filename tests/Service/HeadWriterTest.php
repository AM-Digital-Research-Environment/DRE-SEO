<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\HeadWriter;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The record HeadWriter keeps of what the head says, across the action and
 * layout passes of one request: which signal stands when both write it.
 */
final class HeadWriterTest extends TestCase
{
    public function testTheFirstCanonicalAndRobotsStandUnlessForced(): void
    {
        $view = new PhpRenderer();
        $head = new HeadWriter();

        $head->canonical($view, null);
        $head->canonical($view, '');
        $this->assertFalse($head->has('canonical'), 'nothing written is nothing applied');
        $head->canonical($view, 'https://example.test/s/amira/item/1');
        $head->canonical($view, 'https://example.test/s/amira/item');
        $this->assertSame([['rel' => 'canonical', 'href' => 'https://example.test/s/amira/item/1']], $view->links('canonical'));

        $head->robots($view, 'noindex, follow');
        $head->robots($view, 'max-image-preview:large');
        $this->assertSame(['noindex, follow'], $view->meta('robots'));
        $head->robots($view, 'noindex, nofollow', true);
        $this->assertSame(['noindex, nofollow'], $view->meta('robots'), 'the staging noindex outranks a page');
    }

    public function testOpenGraphMarksEachTagAndMirrorsTheTitle(): void
    {
        $view = new PhpRenderer();
        $head = new HeadWriter();

        $head->openGraph($view, ['og:title' => 'A record', 'og:description' => '', 'og:url' => null]);

        $this->assertSame(['A record'], $view->meta('twitter:title'));
        $this->assertTrue($head->has('og:title'));
        $this->assertFalse($head->has('og:description'), 'an empty value is skipped, not applied');
        $this->assertFalse($head->has('og:url'));
    }

    public function testTheDescriptionAndSignpostsAreKeptForTheLayoutPass(): void
    {
        $view = new PhpRenderer();
        $head = new HeadWriter();
        $this->assertNull($head->writtenDescription());
        $this->assertSame([], $head->writtenSignposts());

        $head->description($view, 'About the record.');
        $links = [['rel' => 'cite-as', 'href' => 'https://example.test/s/amira/item/1']];
        $head->signposts($view, $links);

        $this->assertSame('About the record.', $head->writtenDescription());
        $this->assertSame(['About the record.'], $view->meta('twitter:description'));
        $this->assertSame($links, $head->writtenSignposts());
        $this->assertSame($links, $view->links('cite-as'));
    }

    public function testAnImageIsSizedOnlyWhenTheSizeIsKnown(): void
    {
        $sized = new PhpRenderer();
        (new HeadWriter())->image($sized, 'https://example.test/files/large/a.png', 'AMIRA', [800, 450]);
        $this->assertSame(['800'], $sized->meta('og:image:width'));
        $this->assertSame(['450'], $sized->meta('og:image:height'));
        $this->assertSame(['AMIRA'], $sized->meta('twitter:image:alt'));

        $unsized = new PhpRenderer();
        $head = new HeadWriter();
        $head->image($unsized, 'https://i.ytimg.com/vi/abc/hqdefault.jpg', ' ');
        $this->assertSame([], $unsized->meta('og:image:width'));
        $this->assertSame([], $unsized->meta('og:image:alt'), 'blank alt text is left out');
        $this->assertTrue($head->has('image'));
        $this->assertTrue($head->has('og:image'));
    }

    public function testAnEditorsTitleReplacesTheStack(): void
    {
        $view = new PhpRenderer();
        $view->head['title'] = ['Team', 'AMIRA'];
        $head = new HeadWriter();
        $this->assertSame('Team', $head->leadingTitle($view));

        $head->title($view, 'Our team');

        $this->assertSame(['Our team'], $view->head['title']);
        $this->assertNull($head->leadingTitle(new PhpRenderer()));
    }
}
