<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * The pixel size of an image Omeka serves from its local file store — a media
 * derivative (`/files/large/…`) or an asset (`/files/asset/…`) — so a link
 * preview can declare og:image:width and og:image:height and be laid out
 * before the image loads. Omeka records no derivative dimensions, so the file
 * header is read (getimagesize reads only the first bytes). Anything that is
 * not a local file — a YouTube thumbnail, another store — is unknown, and the
 * tags are simply left out.
 */
final class ImageDimensions
{
    private const DIRECTORIES = ['large', 'medium', 'square', 'original', 'asset'];

    /** @var array<string,array{0:int,1:int}|null> */
    private array $known = [];

    /** @param string|null $basePath the local file store's directory (Omeka's `files/`) */
    public function __construct(private readonly ?string $basePath)
    {
    }

    /** @return array{0:int,1:int}|null width and height, when the file is local and readable */
    public function of(string $url): ?array
    {
        if (array_key_exists($url, $this->known)) {
            return $this->known[$url];
        }
        return $this->known[$url] = $this->measure($url);
    }

    /** @return array{0:int,1:int}|null */
    private function measure(string $url): ?array
    {
        if ($this->basePath === null || $this->basePath === '' || !is_dir($this->basePath)) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('~/files/(' . implode('|', self::DIRECTORIES) . ')/([^/]+)\z~', $path, $m)) {
            return null;
        }
        $name = rawurldecode($m[2]);
        if ($name === '' || $name[0] === '.' || str_contains($name, '/') || str_contains($name, '\\')) {
            return null;
        }
        $file = rtrim($this->basePath, '/\\') . '/' . $m[1] . '/' . $name;
        if (!is_file($file)) {
            return null;
        }
        $size = @getimagesize($file);
        if (!is_array($size) || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
            return null;
        }
        return [(int) $size[0], (int) $size[1]];
    }
}
