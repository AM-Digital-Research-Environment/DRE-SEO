<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Laminas\Http\Client\Adapter\Test;

/**
 * Laminas' test adapter, which answers with canned responses instead of the
 * network, also keeping the body of every request written to it.
 */
final class RecordingHttpAdapter extends Test
{
    /** @var string[] */
    public array $bodies = [];

    /**
     * @param string $method
     * @param \Laminas\Uri\Uri|string $uri
     * @param string $httpVer
     * @param array<string,mixed> $headers
     * @param string $body
     * @return string
     */
    public function write($method, $uri, $httpVer = '1.1', $headers = [], $body = '')
    {
        $this->bodies[] = (string) $body;
        return parent::write($method, $uri, $httpVer, $headers, $body);
    }
}
