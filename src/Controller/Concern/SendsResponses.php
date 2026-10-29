<?php
declare(strict_types=1);

namespace DRESeo\Controller\Concern;

use DRESeo\Service\HttpCache;
use Laminas\Http\Response;

/**
 * Body-and-headers plumbing for the module's non-HTML endpoints: the
 * sitemaps, robots.txt, the IndexNow key file and the /cite downloads.
 *
 * These controllers return raw XML, text or a citation file rather than a
 * ViewModel, and each had hand-rolled its own xml(), text(), notFound() and
 * fileResponse(). Ported from IWAC SEO, with DRE's header lines in DRE's
 * order. The rule they share is stated once here: a machine-readable
 * document is served `X-Robots-Tag: noindex` unless the caller says
 * otherwise, so the raw feed never competes with the HTML page it describes
 * (robots.txt and the key file opt out, as they always have). The citation
 * downloads are also cacheable, through {@see respondCacheable()}.
 *
 * Requires the using class to be a Laminas AbstractActionController (for
 * getResponse() and getRequest()).
 */
trait SendsResponses
{
    /**
     * @param array<string,string> $headers extra header lines, after the
     *   content type and the noindex
     */
    private function respond(
        string $body,
        string $contentType,
        int $status = 200,
        array $headers = [],
        bool $noindex = true
    ): Response {
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->setContent($body);

        $responseHeaders = $response->getHeaders();
        $responseHeaders->addHeaderLine('Content-Type', $contentType);
        if ($noindex) {
            $responseHeaders->addHeaderLine('X-Robots-Tag', 'noindex');
        }
        foreach ($headers as $name => $value) {
            $responseHeaders->addHeaderLine($name, $value);
        }
        return $response;
    }

    /**
     * A generated document with cache validators ({@see HttpCache}): a strong
     * ETag over the body, the record's last change as Last-Modified, and a
     * short public max-age, so a reference manager or a cache revalidates
     * cheaply. A conditional request whose copy is current is answered 304,
     * with the validators and no body.
     *
     * @param array<string,string> $headers     sent with every answer, 304 included, first
     * @param array<string,string> $bodyHeaders sent with the body only, after its content type
     */
    private function respondCacheable(
        string $body,
        string $contentType,
        ?\DateTimeInterface $modified,
        array $headers = [],
        array $bodyHeaders = []
    ): Response {
        $etag = HttpCache::etag($body);
        $headers += ['Cache-Control' => 'public, max-age=' . HttpCache::MAX_AGE, 'ETag' => $etag];
        $lastModified = HttpCache::lastModified($modified);
        if ($lastModified !== null) {
            $headers['Last-Modified'] = $lastModified;
        }
        $headers['X-Robots-Tag'] = 'noindex';

        $response = $this->getResponse();
        $responseHeaders = $response->getHeaders();
        foreach ($headers as $name => $value) {
            $responseHeaders->addHeaderLine($name, $value);
        }

        $request = $this->getRequest();
        if (
            HttpCache::notModified(
                $etag,
                $modified,
                self::requestHeader($request, 'If-None-Match'),
                self::requestHeader($request, 'If-Modified-Since')
            )
        ) {
            $response->setStatusCode(304);
            $response->setContent('');
            return $response;
        }

        $response->setContent($body);
        $responseHeaders->addHeaderLine('Content-Type', $contentType);
        foreach ($bodyHeaders as $name => $value) {
            $responseHeaders->addHeaderLine($name, $value);
        }
        return $response;
    }

    /** A request header's value, or null when it is absent or unreadable. */
    private static function requestHeader(object $request, string $name): ?string
    {
        try {
            $header = $request->getHeader($name);
        } catch (\Throwable $e) {
            return null; // a malformed date, say: no condition to meet
        }
        return is_object($header) ? (string) $header->getFieldValue() : null;
    }

    /** A bare status response with no body (404 …). */
    private function respondWithStatus(int $code): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($code);
        return $response;
    }
}
