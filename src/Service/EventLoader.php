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
    private string $loaderName;
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
        $this->loaderName = getenv('LOADER_NAME') ?: $this->ownerId;
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

        foreach ($sources as $sourceName => $sourceConfig) {

            usleep($this->loopSleepUs);

            try {
                $this->logger->info('attempting to acquire lease for source {source} owner {owner}', ['source' => $sourceName, 'owner' => $this->loaderName]);
                $acquired = $this->leaseManager->tryAcquireLease($sourceName, $this->ownerId, $this->maxCallDurationMs);
            } catch (LeaseAcquireException $e) {
                $this->logger->error('Lease acquire failed for source {source}: {err}', ['source' => $sourceName, 'err' => $e->getMessage()]);
                // pause before next attempt
                $ms2 = (int)ceil($this->loopSleepUs / 1000);
                $this->logger->debug('pausing for {ms}ms after lease error before next source', ['ms' => $ms2]);
                usleep($this->loopSleepUs);
                continue;
            }

            if (!$acquired) {
                // someone else owns it
                $this->logger->debug('lease not acquired for source {source}', ['source' => $sourceName]);
                // pause before next attempt
                $ms3 = (int)ceil($this->loopSleepUs / 1000);
                $this->logger->debug('pausing for {ms}ms after failed acquire before next source', ['ms' => $ms3]);
                usleep($this->loopSleepUs);
                continue;
            }
            try {
                $this->logger->info('lease acquired for source {source} owner {owner}', ['source' => $sourceName, 'owner' => $this->loaderName]);

                // authoritative checkpoint from storage
                $lastStored = $this->storage->fetchLastSourceEventId($sourceName) ?? -1;

                // Read runtime metadata from coordination store (Redis) rather than trusting registry-provided meta
                $coordMeta = null;
                try {
                    $coordMeta = $this->leaseManager->getSourceMetadata($sourceName);
                } catch (\Throwable $t) {
                    // If metadata read fails, log and continue using storage checkpoint
                    $this->logger->warning('failed reading coordination metadata for source {source}: {err}', ['source' => $sourceName, 'err' => $t->getMessage()]);
                }

                if ($coordMeta === null && is_array($sourceConfig)) {
                    $coordMeta = $sourceConfig;
                }

                if (is_array($coordMeta) && array_key_exists('lastStoredSourceEventId', $coordMeta)) {
                    $advisoryLastStored = $coordMeta['lastStoredSourceEventId'];
                    if ($advisoryLastStored !== null && (int)$advisoryLastStored !== $lastStored) {
                        $this->logger->warning(
                            'coordination metadata for source {source} is out of sync: advisory={advisory} authoritative={authoritative}',
                            ['source' => $sourceName, 'advisory' => (int)$advisoryLastStored, 'authoritative' => $lastStored]
                        );
                    }
                }

                try {
                    // Enforce per-source throttle based on shared coordination metadata.
                    $now = $this->clock->now();
                    $nextCallAfter = null;
                    if (is_array($coordMeta) && array_key_exists('nextCallAfter', $coordMeta)) {
                        $nextCallAfter = (int)$coordMeta['nextCallAfter'];
                    }

                    if ($nextCallAfter !== null && $now < $nextCallAfter) {
                        $waitMs = $nextCallAfter - $now;
                        $this->logger->info('throttle: skipping source {source} for another {ms}ms', ['ms' => $waitMs, 'source' => $sourceName]);
                        continue;
                    }

                    $this->logger->info('fetching events for source {source} after {after}', ['source' => $sourceName, 'after' => $lastStored]);
                    $startMs = $this->clock->now();
                    $events = $this->client->fetch($sourceName, $lastStored, 1000);
                    $dur = $this->clock->now() - $startMs;
                } catch (TransientEventSourceException | PermanentEventSourceException $e) {
                    $this->logger->error('Source fetch failed for source {source}: {err}', ['source' => $sourceName, 'err' => $e->getMessage()]);
                    continue;
                }

                try {
                    $this->leaseManager->setNextCallAfter($sourceName, $this->clock->now() + $this->throttleMs);
                } catch (\Throwable $t) {
                    $this->logger->warning('failed updating nextCallAfter for source {source}: {err}', ['source' => $sourceName, 'err' => $t->getMessage()]);
                }

                $count = count($events);
                if ($count === 0) {
                    // no new events — let round-robin continue
                    $this->logger->info('no new events for source {source}', ['source' => $sourceName]);
                    continue;
                }

                $this->logger->info('fetched {count} events from source {source} in {ms}ms', ['source' => $sourceName, 'count' => $count, 'ms' => $dur]);

                // store events
                try {
                    $this->logger->info('storing {count} events for source {source}', ['source' => $sourceName, 'count' => $count]);
                    $this->storage->storeEvents($sourceName, $events);
                    $this->logger->info('stored {count} events for source {source}', ['source' => $sourceName, 'count' => $count]);
                } catch (EventStorageException | TransientStorageException $e) {
                    $this->logger->error('Storage failed for source {source}: {err}', ['source' => $sourceName, 'err' => $e->getMessage()]);
                    continue;
                }

                // advance cursor
                $last = end($events);
                if ($last instanceof \App\Model\Event) {
                    $this->cursorStore->setLastStoredSourceEventId($sourceName, $last->sourceEventId);
                    $this->logger->info('advanced cursor for source {source} to {id}', ['source' => $sourceName, 'id' => $last->sourceEventId]);

                    try {
                        $this->leaseManager->setLastStoredSourceEventId($sourceName, $last->sourceEventId);
                    } catch (\Throwable $t) {
                        $this->logger->warning('failed updating advisory last stored id for source {source}: {err}', ['source' => $sourceName, 'err' => $t->getMessage()]);
                    }
                }
            } finally {
                try {
                    $this->leaseManager->releaseLease($sourceName, $this->ownerId);
                    $this->logger->info('released lease for source {source} owner {owner}', ['source' => $sourceName, 'owner' => $this->loaderName]);
                } catch (LeaseReleaseException $e) {
                    $this->logger->error('Failed releasing lease for source {source}: {err}', ['source' => $sourceName, 'err' => $e->getMessage()]);
                }
                // pause between processing sources so logs and external systems can observe throttling
                try {
                    $ms = (int)ceil($this->loopSleepUs / 1000);
                    $this->logger->debug('pausing for {ms}ms before next source', ['ms' => $ms]);
                    usleep($this->loopSleepUs);
                } catch (\Throwable $t) {
                    // ignore sleep errors
                }
            }
        }
    }
}
