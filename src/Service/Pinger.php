<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\Http\Client as HttpClient;

/**
 * Submits changed URLs to IndexNow (Bing, Yandex and other participating
 * engines) so they are crawled sooner. One POST carries up to 10,000 URLs.
 *
 * Google is deliberately NOT pinged: its sitemap-ping endpoint was retired in
 * 2023, and Google discovers content through the robots.txt `Sitemap:` line and
 * Search Console instead.
 */
class Pinger
{
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';
    private const MAX_URLS = 10000;

    public function __construct(private readonly HttpClient $httpClient)
    {
    }

    /** The submission could not be sent (no response, or nothing to send). */
    public const NOT_SENT = 0;

    /**
     * @param string[] $urls
     * @return int the HTTP status IndexNow answered (200/202 accepted), or
     *             {@see NOT_SENT}
     */
    public function submitIndexNow(string $host, string $key, string $keyLocation, array $urls): int
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (!IndexNowKey::isValid($key) || $urls === []) {
            return self::NOT_SENT;
        }
        $urls = array_slice($urls, 0, self::MAX_URLS);

        $payload = json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $keyLocation,
            'urlList'     => $urls,
        ]);
        if ($payload === false) {
            return self::NOT_SENT;
        }

        try {
            // Clone so per-request state never leaks into the shared client.
            $client = clone $this->httpClient;
            $client->setUri(self::ENDPOINT);
            $client->setMethod('POST');
            $client->setOptions(['timeout' => 10]);
            $client->setHeaders(['Content-Type' => 'application/json; charset=utf-8']);
            $client->setRawBody($payload);
            $response = $client->send();
            return (int) $response->getStatusCode();
        } catch (\Throwable $e) {
            return self::NOT_SENT;
        }
    }

    public static function accepted(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Whether a failed submission is worth retrying: no answer, a server
     * error, a timeout or rate limiting. Any other 4xx (400 bad request, 403
     * key not valid, 422 URLs not of this host) will fail the same way again.
     */
    public static function retryable(int $status): bool
    {
        return $status === self::NOT_SENT || $status === 408 || $status === 429 || $status >= 500;
    }
}
