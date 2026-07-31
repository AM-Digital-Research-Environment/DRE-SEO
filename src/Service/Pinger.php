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

    /**
     * @param string[] $urls
     * @return bool true when the engine accepted the submission
     */
    public function submitIndexNow(string $host, string $key, string $keyLocation, array $urls): bool
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (!IndexNowKey::isValid($key) || $urls === []) {
            return false;
        }
        $urls = array_slice($urls, 0, self::MAX_URLS);

        $payload = json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $keyLocation,
            'urlList'     => $urls,
        ]);
        if ($payload === false) {
            return false;
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
            return $response->getStatusCode() < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
