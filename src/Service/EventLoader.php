<?php
declare(strict_types=1);

namespace App\Service;

use App\Contract\EventLoaderInterface;
use App\Contract\EventSourceClientInterface;
use App\Contract\EventSourceRegistryInterface;
use App\Contract\EventStorageInterface;
use App\Contract\SourceCursorStoreInterface;
use App\Contract\SourceLeaseManagerInterface;
use App\Contract\ClockInterface;
use Psr\Log\LoggerInterface;
use App\Exception\TransientEventSourceException;
use App\Exception\PermanentEventSourceException;
use App\Exception\EventStorageException;
use App\Exception\TransientStorageException;
use App\Exception\LeaseAcquireException;
use App\Exception\LeaseReleaseException;

final class EventLoader implements EventLoaderInterface
{
    private EventSourceRegistryInterface $registry;
    private EventSourceClientInterface $client;
    private EventStorageInterface $storage;
    private SourceCursorStoreInterface $cursorStore;
    private SourceLeaseManagerInterface $leaseManager;
    private ClockInterface $clock;
    private LoggerInterface $logger;
    private int $throttleMs;
    private int $maxCallDurationMs;
    private bool $running = true;
    private string $ownerId;
    private int $loopSleepUs;

    public function __construct(
        EventSourceRegistryInterface $registry,
        EventSourceClientInterface $client,
        EventStorageInterface $storage,
        SourceCursorStoreInterface $cursorStore,
        SourceLeaseManagerInterface $leaseManager,
        ClockInterface $clock,
        LoggerInterface $logger,
        int $throttleMs = 200,
        int $maxCallDurationMs = 300000
    ) {
        $this->registry = $registry;
        $this->client = $client;
        $this->storage = $storage;
        $this->cursorStore = $cursorStore;
        $this->leaseManager = $leaseManager;
        $this->clock = $clock;
        $this->logger = $logger;
        // allow overriding defaults via environment variables for runtime config
        $envThrottle = getenv('THROTTLE_MS');
        $this->throttleMs = $envThrottle !== false ? (int)$envThrottle : $throttleMs;

        $envMax = getenv('MAX_CALL_DURATION_MS');
        $this->maxCallDurationMs = $envMax !== false ? (int)$envMax : $maxCallDurationMs;
        $this->ownerId = uniqid('loader_', true);
        $envLoop = getenv('LOOP_SLEEP_US');
        $this->loopSleepUs = $envLoop !== false ? (int)$envLoop : 100_000;
    }

    public function runLoop(): void
    {
        while ($this->running) {
            $this->runOnce();
            // avoid busy-loop; sleep a short while (configurable via LOOP_SLEEP_US in microseconds)
            usleep($this->loopSleepUs);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function runOnce(): void
    {
        $sources = $this->registry->getSources();

        foreach ($sources as $sourceName => $meta) {
            try {
                $acquired = $this->leaseManager->tryAcquireLease($sourceName, $this->ownerId, $this->maxCallDurationMs);
            } catch (LeaseAcquireException $e) {
                $this->logger->error('Lease acquire failed', ['source' => $sourceName, 'err' => $e->getMessage()]);
                continue;
            }

            if (!$acquired) {
                // someone else owns it
                continue;
            }

            try {
                // authoritative checkpoint from storage
                $lastStored = $this->storage->fetchLastSourceEventId($sourceName) ?? -1;

                try {
                    $events = $this->client->fetch($sourceName, $lastStored, 1000);
                } catch (TransientEventSourceException | PermanentEventSourceException $e) {
                    $this->logger->error('Source fetch failed', ['source' => $sourceName, 'err' => $e->getMessage()]);
                    continue;
                }

                $count = count($events);
                if ($count === 0) {
                    // no new events — let round-robin continue
                    $this->logger->info('No new events', ['source' => $sourceName]);
                    continue;
                }

                // Log only how many events were fetched (per request)
                $this->logger->info(sprintf('fetched %d events', $count));

                // store events
                try {
                    $this->storage->storeEvents($sourceName, $events);
                } catch (EventStorageException | TransientStorageException $e) {
                    $this->logger->error('Storage failed', ['source' => $sourceName, 'err' => $e->getMessage()]);
                    continue;
                }

                // advance cursor
                $last = end($events);
                if ($last instanceof \App\Model\Event) {
                    $this->cursorStore->setLastStoredSourceEventId($sourceName, $last->sourceEventId);
                }
            } finally {
                try {
                    $this->leaseManager->releaseLease($sourceName, $this->ownerId);
                } catch (LeaseReleaseException $e) {
                    $this->logger->error('Failed releasing lease', ['source' => $sourceName, 'err' => $e->getMessage()]);
                }
            }
        }
    }
}
