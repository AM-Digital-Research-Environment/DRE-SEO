<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use Doctrine\DBAL\DriverManager;
use DRESeo\Job\PingSearchEngines;
use DRESeo\Service\Pinger;
use DRESeo\Service\PingOutcome;
use DRESeo\Service\PingQueue;
use DRESeo\Service\PingRepository;
use DRESeo\Service\SettingsGate;
use DRESeo\Test\Integration\Double\InMemorySettings;
use DRESeo\Test\Integration\Double\RecordingHttpAdapter;
use Laminas\Http\Client;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Mock as MockWriter;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Entity\Job;
use Omeka\Job\Dispatcher;
use Omeka\Log\Processor\PsrPlaceholder;
use PHPUnit\Framework\TestCase;

/**
 * The drain job end to end over a real outbox table: what IndexNow answers
 * decides whether the batch is acknowledged, retried or parked, and what the
 * log tells the administrator.
 */
final class PingSearchEnginesTest extends TestCase
{
    private const HOST = 'data.africamultiple.uni-bayreuth.de';

    private PingRepository $outbox;
    private RecordingHttpAdapter $adapter;
    private MockWriter $log;
    private InMemorySettings $settings;

    private function drain(): void
    {
        $client = new Client();
        $client->setAdapter($this->adapter);

        // Omeka's logger interpolates {placeholders} from the context.
        $logger = new Logger();
        $logger->addWriter($this->log);
        $logger->addProcessor(new PsrPlaceholder());

        $services = new ServiceManager();
        $settings = new SettingsGate($this->settings);
        $services->setService(SettingsGate::class, $settings);
        $services->setService(PingQueue::class, new PingQueue($settings, $this->createStub(Dispatcher::class), $this->outbox, $logger));
        $services->setService(Pinger::class, new Pinger($client));
        $services->setService('Omeka\\Logger', $logger);

        (new PingSearchEngines(new Job(), $services))->perform();
    }

    protected function setUp(): void
    {
        $this->settings = new InMemorySettings([
            'dre_seo_ping_enabled' => '1',
            'dre_seo_indexnow_key' => 'a1b2c3d4e5f60718',
        ]);
        $this->outbox = new PingRepository(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->outbox->install();
        $this->outbox->push('https://' . self::HOST . '/s/amira/item/1');
        $this->outbox->push('https://' . self::HOST . '/s/amira/page/about');
        $this->adapter = new RecordingHttpAdapter();
        $this->log = new MockWriter();
    }

    /** @return string[] */
    private function messages(string $priority): array
    {
        $out = [];
        foreach ($this->log->events as $event) {
            if ($event['priorityName'] === $priority) {
                $out[] = $event['message'];
            }
        }
        return $out;
    }

    public function testAnAcceptedBatchIsAcknowledged(): void
    {
        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 0, 'failed' => 0], $this->outbox->counts());
        self::assertSame(['DRESeo: IndexNow accepted 2 URL(s) for ' . self::HOST . ' (HTTP 202).'], $this->messages('INFO'));
        // One request for the one origin, naming the key file on that origin.
        self::assertCount(1, $this->adapter->bodies);
        self::assertSame([
            'host' => self::HOST,
            'key' => 'a1b2c3d4e5f60718',
            'keyLocation' => 'https://' . self::HOST . '/a1b2c3d4e5f60718.txt',
            'urlList' => ['https://' . self::HOST . '/s/amira/item/1', 'https://' . self::HOST . '/s/amira/page/about'],
        ], json_decode($this->adapter->bodies[0], true));
    }

    /** IndexNow takes one host per request, and checks the key on that host. */
    public function testEachOriginIsSubmittedOnItsOwn(): void
    {
        $this->outbox->push('http://staging.example.test:8080/s/amira/item/9');
        $this->adapter->setResponse("HTTP/1.1 200 OK\r\n\r\n");
        $this->adapter->addResponse("HTTP/1.1 200 OK\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 0, 'failed' => 0], $this->outbox->counts());
        $requests = array_map(static fn (string $body): array => json_decode($body, true), $this->adapter->bodies);
        self::assertSame([self::HOST, 'staging.example.test'], array_column($requests, 'host'));
        self::assertSame('http://staging.example.test:8080/a1b2c3d4e5f60718.txt', $requests[1]['keyLocation']);
        self::assertSame(['http://staging.example.test:8080/s/amira/item/9'], $requests[1]['urlList']);
    }

    public function testARefusedBatchIsParkedAsFailedWithAWarningNamingTheStatus(): void
    {
        $this->adapter->setResponse("HTTP/1.1 403 Forbidden\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 0, 'failed' => 2], $this->outbox->counts());
        $warnings = $this->messages('WARN');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('refused 2 URL(s) for ' . self::HOST . ' with HTTP 403', $warnings[0]);
        self::assertStringContainsString('https://' . self::HOST . '/a1b2c3d4e5f60718.txt', $warnings[0]);
        self::assertStringContainsString('--retry-failed', $warnings[0]);
        // The figures travel as context too, for a log writer that keeps it.
        $extra = $this->log->events[0]['extra'];
        self::assertSame([2, self::HOST, 403], [$extra['count'], $extra['host'], $extra['status']]);
    }

    public function testAServerErrorIsRetriedNotParked(): void
    {
        $this->adapter->setResponse("HTTP/1.1 503 Service Unavailable\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 2, 'failed' => 0], $this->outbox->counts());
        self::assertFalse($this->outbox->hasDue(), 'backing off');
        self::assertSame([], $this->messages('WARN'));
    }

    /** Queue URLs item/3, item/4, … until $total are waiting. */
    private function fillTo(int $total): void
    {
        for ($i = 3; $i <= $total; $i++) {
            $this->outbox->push('https://' . self::HOST . '/s/amira/item/' . $i);
        }
    }

    /** One drain's batch is incremental editing: sent as usual, in one request. */
    public function testAtTheBulkThresholdTheBatchIsSent(): void
    {
        $this->fillTo(PingQueue::BULK_THRESHOLD);
        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->drain();

        self::assertCount(1, $this->adapter->bodies);
        self::assertCount(PingQueue::BULK_THRESHOLD, json_decode($this->adapter->bodies[0], true)['urlList']);
        self::assertSame(['pending' => 0, 'failed' => 0], $this->outbox->counts());
        self::assertSame(['DRESeo: IndexNow accepted 200 URL(s) for ' . self::HOST . ' (HTTP 202).'], $this->messages('INFO'));
    }

    /**
     * More than one drain sends is a sync: nothing goes to IndexNow, the URLs
     * leave the queue as handled (the sitemap announces them), and the log
     * says so with the count.
     */
    public function testAboveTheBulkThresholdNothingIsSentAndTheUrlsAreAcknowledged(): void
    {
        $this->fillTo(PingQueue::BULK_THRESHOLD + 1);
        $this->adapter->setNextRequestWillFail(true);
        $this->drain();

        self::assertSame([], $this->adapter->bodies);
        self::assertSame(['pending' => 0, 'failed' => 0], $this->outbox->counts());
        self::assertSame(
            ['DRESeo: skipped IndexNow for a bulk change of 201 URL(s), most likely a sync; the sitemap covers their discovery.'],
            $this->messages('INFO')
        );
        self::assertSame([], $this->messages('WARN'));
    }

    /**
     * URLs re-queued with --retry-failed were each sent before; however many
     * there are, they go out 200 per drain, not discarded as a bulk change.
     */
    public function testRequeuedFailedUrlsAreSentWhateverTheirNumber(): void
    {
        $this->fillTo(PingQueue::BULK_THRESHOLD + 50);
        $now = time() + 1;
        foreach ([$this->outbox->claim(now: $now), $this->outbox->claim(now: $now)] as $refused) {
            $this->outbox->finish($refused, PingOutcome::Refused, $now);
        }
        self::assertSame(['pending' => 0, 'failed' => 250], $this->outbox->counts());
        $this->outbox->retryFailed();

        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->drain();

        self::assertCount(1, $this->adapter->bodies);
        self::assertCount(PingQueue::CAP, json_decode($this->adapter->bodies[0], true)['urlList']);
        self::assertSame(['pending' => 50, 'failed' => 0], $this->outbox->counts());
    }

    /** Nothing is claimed, so the URLs go out untouched once a valid key is saved. */
    public function testAMalformedKeyLeavesTheQueueAlone(): void
    {
        $this->settings->set('dre_seo_indexnow_key', 'not-a-key');
        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 2, 'failed' => 0], $this->outbox->counts());
        self::assertTrue($this->outbox->hasDue());
        self::assertCount(1, $this->messages('WARN'));
    }

    public function testNothingIsSentWhilePingingIsSwitchedOff(): void
    {
        $this->settings->set('dre_seo_ping_enabled', '0');
        $this->adapter->setNextRequestWillFail(true);
        $this->drain();

        self::assertSame(['pending' => 2, 'failed' => 0], $this->outbox->counts());
        self::assertTrue($this->outbox->hasDue());
        self::assertSame([], $this->log->events);
    }
}
