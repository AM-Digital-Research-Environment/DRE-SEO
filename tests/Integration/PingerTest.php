<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Service\Pinger;
use DRESeo\Service\PingOutcome;
use DRESeo\Test\Integration\Double\RecordingHttpAdapter;
use Laminas\Http\Client;
use PHPUnit\Framework\TestCase;

/**
 * The Pinger reports what IndexNow answered, through Laminas' real HTTP client
 * with its test adapter standing in for the network.
 */
final class PingerTest extends TestCase
{
    private const URLS = ['https://data.africamultiple.uni-bayreuth.de/s/amira/item/1'];

    private RecordingHttpAdapter $adapter;

    private Pinger $pinger;

    protected function setUp(): void
    {
        $this->adapter = new RecordingHttpAdapter();
        $client = new Client();
        $client->setAdapter($this->adapter);
        $this->pinger = new Pinger($client);
    }

    /** @param string[] $urls */
    private function submit(string $key = 'a1b2c3d4e5f60718', array $urls = self::URLS): int
    {
        return $this->pinger->submitIndexNow(
            'data.africamultiple.uni-bayreuth.de',
            $key,
            'https://data.africamultiple.uni-bayreuth.de/' . $key . '.txt',
            $urls
        );
    }

    public function testReturnsTheStatusIndexNowAnswered(): void
    {
        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->assertSame(202, $this->submit());
        $this->assertSame(PingOutcome::Accepted, PingOutcome::fromStatus(202));

        $this->adapter->setResponse("HTTP/1.1 403 Forbidden\r\n\r\n");
        $this->assertSame(403, $this->submit());

        $this->adapter->setResponse("HTTP/1.1 503 Service Unavailable\r\n\r\n");
        $this->assertSame(503, $this->submit());
    }

    public function testATransportFailureIsNotSent(): void
    {
        $this->adapter->setNextRequestWillFail(true);
        $this->assertSame(Pinger::NOT_SENT, $this->submit());
    }

    /** A key IndexNow could never verify, or nothing to say, is not sent at all. */
    public function testAMalformedKeyOrAnEmptyListIsNeverSent(): void
    {
        $this->adapter->setResponse("HTTP/1.1 200 OK\r\n\r\n");
        $this->assertSame(Pinger::NOT_SENT, $this->submit('not-hex!'));
        $this->assertSame(Pinger::NOT_SENT, $this->submit('abc'));
        $this->assertSame(Pinger::NOT_SENT, $this->submit(urls: ['', '']));
        $this->assertSame([], $this->adapter->bodies, 'nothing reached the network');

        $this->assertSame(200, $this->submit());
    }

    public function testTheSubmissionIsTheJsonIndexNowDocuments(): void
    {
        $this->adapter->setResponse("HTTP/1.1 200 OK\r\n\r\n");
        $this->submit(urls: [self::URLS[0], self::URLS[0], 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/2']);

        $this->assertSame([
            'host' => 'data.africamultiple.uni-bayreuth.de',
            'key' => 'a1b2c3d4e5f60718',
            'keyLocation' => 'https://data.africamultiple.uni-bayreuth.de/a1b2c3d4e5f60718.txt',
            'urlList' => [self::URLS[0], 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/2'],
        ], json_decode($this->adapter->bodies[0], true), 'one entry per URL');
    }
}
