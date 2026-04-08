# Initial Project Specification: Event Loading System

## 1. Purpose of the document

This document defines the initial product and functional specification for the core of a system that loads events from multiple remote sources into centralized storage.

The goal of this phase is not to define a complete product, but to align on:

- the business problem we are solving
- the minimum scope of the first version
- the key system behavior rules
- the technical boundaries of the task
- the open questions that should be resolved before implementation

## 2. Context and problem

The system must continuously load new events from multiple external sources. Each source returns events sorted by ascending identifier, and loading is based on the "last known event ID" principle.

The main business problem is not only data retrieval, but also safe coordination of multiple parallel loader instances so that:

- the same event is never requested over the network more than once
- the same source is never called more frequently than once every 200 ms, globally across all instances
- the system can operate in a distributed environment, including multiple servers

This means the core of the task is coordination, not network communication or database implementation.

## 3. Goals of the first version

The first version should provide:

- a definition of the key interfaces for retrieval, storage, and orchestration
- an implementation of the main loader flow
- round-robin processing of multiple sources
- safe parallel execution without duplicate network retrieval of the same event
- enforcement of the global 200 ms throttling rule per source
- tolerance to remote source failures so the whole process does not crash
- logging of source unavailability or request failures

- Implementation target: PHP 8.1; the reference implementation MUST use the Symfony framework (console command + services).

## 4. Scope of the first version

In scope:

- the domain model required for the loader
- an interface for the event source client
- an interface for event storage
- an interface or service for progress coordination and per-source locking
- the main event loader running in an infinite loop
- a basic error-handling strategy and retry behavior at the loop level
 - a basic error-handling strategy: log failing sources and skip them in the current iteration (no automatic retry/backoff is required by the reference implementation)
- the ability to test the loader with mocks and stubs

- a minimal PHP/Symfony project skeleton (Composer manifest, PSR-4 autoloading), an `EventLoader` implemented as a Symfony service and/or console command, and a `README.md` with run instructions.

Out of scope:

- a concrete database implementation
- a concrete HTTP/gRPC/queue client implementation
- an administrative interface
- a monitoring dashboard
- throughput-oriented scaling beyond the minimum required distributed coordination
- backlog-draining optimizations, prefetching, batching strategies beyond the source contract, or other performance tuning not required to satisfy the task
- optimizations that are not necessary to satisfy the task

## 5. Domain model

### 5.1 Event Source

Each event source:

- has a unique name
- is available over the network
- supports retrieval of events strictly greater than a given `sourceEventId`
- returns at most 1000 events per request

### 5.2 Event

For the purposes of the core, it is sufficient to assume:

- `sourceName` identifies the source
- `sourceEventId` is unique within a source
- `sourceEventId` grows monotonically over time
- `sourceEventId` represents the source's external event identifier. Persistent storage MAY use an internal primary key for each stored row, but MUST also persist the `sourceEventId` so it can be used as the authoritative checkpoint.
- an event payload exists, but the loader core does not need to know its internal format
- events are immutable, which reduces the business risk of duplicate processing at the storage level; however, immutability does not remove the coordination requirement to avoid duplicate network retrieval.

### 5.3 Loader Instance

A single loader instance:

- participates in a shared, distributed processing workflow
- iterates through sources in round-robin order
- must verify before each request whether it is currently allowed to process that source
- advances the checkpoint for a source after successfully storing events

## 6. Functional requirements

### FR-1 Retrieval by checkpoint

For each source, the system must retrieve events with the condition `sourceEventId > lastStoredSourceEventId`.

### FR-2 Batch limit

A single network request must not expect more than 1000 events.

### FR-3 Infinite processing

The loader must run in an infinite loop until stopped by an external process.

### FR-4 Round-robin distribution

Sources must be processed in round-robin order, without starving any individual source.

### FR-5 Global per-source throttling

There must be at least a 200 ms interval between any two consecutive requests to the same source, regardless of which loader instance sends the request.

### FR-6 No duplicate network retrieval

The same event from the same source must not be transferred over the network more than once during system operation. This is the key integrity requirement.
See Section 8.3 for crash/recovery limitations and mitigation; duplicate retrievals must be minimized during normal operation.

### FR-7 Event storage

After a successful retrieval, the loader must pass the events to the storage layer for durable persistence.

### FR-8 Checkpoint advancement only after successful storage

A source checkpoint may be updated only after the batch has been stored successfully.

### FR-9 Source failures

If a source returns an error, the loader must:

- record the problem in the log
- skip that source in the current iteration
- continue processing the remaining sources

### FR-10 Empty response

If a source has no new events, the loader must not treat that as an error and must continue round-robin processing.

## 7. Non-functional requirements

### NFR-1 Distributed safety

The solution must be feasible for multiple instances running on different servers.

### NFR-2 Minimal complexity

The simplest mechanism that satisfies the task requirements should be preferred.

### NFR-2a Throughput is not a design goal

The fact that a source may return up to 1000 events per request must be treated as a source API constraint, not as a requirement to optimize for high throughput or large backlog processing. Design choices should prioritize correctness and minimal complexity over performance optimization.

### NFR-3 Protocol independence

The interfaces must not be coupled to any specific network protocol or message format.

### NFR-4 Testability

The main loader must be testable without real network calls and without a real database.

### NFR-5 Resilience to source failures

A failure in one source must not stop processing of the other sources.

## 8. Proposed solution concept

This is not an implementation decision, but a recommended direction that currently best covers the task requirements.

### 8.1 Required abstractions

At minimum, the following set of abstractions is recommended:

- `EventSourceClientInterface`
 - `EventSourceClientInterface`
  - retrieves events from a specific source after the given `lastStoredSourceEventId`.
  - method signature (core contract): `fetch(string $sourceName, int $afterSourceEventId, int $limit = 1000): Event[]` — the method returns an array of `Event` objects and MUST NOT return protocol-specific types
- `EventStorageInterface`
 - `EventStorageInterface`
  - durably stores a batch of events
  - contract (examples):
    - `public function storeEvents(string $sourceName, Event[] $events): void` — stores the provided events; if the method returns without throwing, the events are considered durably persisted.
    - `public function fetchLastSourceEventId(string $sourceName): ?int` — returns the highest `sourceEventId` persisted for the given source, or `null` if none exist. This method provides the authoritative checkpoint used by the loader before performing a remote fetch.
- `EventSourceRegistryInterface`
  - provides the list of configured sources and their order for round-robin processing
- `SourceCursorStoreInterface`
  - reads and writes the last successfully stored `sourceEventId` per source
- `SourceLeaseManagerInterface`
  - acquires distributed processing rights for a source and enforces the 200 ms interval
 - advisory hints (for example, an observed `lastStoredSourceEventId`) MUST be stored in `source:{name}` metadata or validated against persistent storage; the lease itself is authoritative only for ownership and TTL semantics and MUST NOT embed authoritative checkpoint values.
- `EventLoaderInterface`
  - starts the main loop or a single loader iteration
- `ClockInterface`
  - abstracts time for testing throttling logic
- `LoggerInterface`
  - logs errors and source unavailability

Implementation note: these abstractions will be defined as PHP `interface`s and modeled as Symfony services in the reference implementation. The reference implementation MUST provide only the PHP `interface` definitions and Symfony wiring (service configuration); concrete infra adapters/clients (HTTP, gRPC, queue clients) and storage backends are explicitly OUT OF SCOPE for the first version and do not need to be implemented. Use Composer for dependency management and PSR‑4 autoloading.

8.1.1 Interface method signatures (recommended)

To remove ambiguity and make the reference implementation straightforward, the following example method signatures are recommended for the interfaces above. These are descriptive contracts (not language‑specific code), and the exact names may be adjusted in the implementation as long as semantics are preserved.

- `EventSourceClientInterface`
  - `fetch(string $sourceName, int $afterSourceEventId, int $limit = 1000): Event[]`

- `EventStorageInterface`
  - `storeEvents(string $sourceName, Event[] $events): void` — persist the batch atomically for the call.
  - `fetchLastSourceEventId(string $sourceName): ?int` — return the largest persisted `sourceEventId` for the source or `null` if none.

- `EventSourceRegistryInterface`
  - `getSources(): array` — return a map/object where keys are `sourceName` and values are JSON-serializable objects representing the per-source metadata. Each value MUST be suitable for direct storage as the Redis value of `source:{name}` (i.e., the loader can `json_encode()` the value and write it to `source:{name}` without further transformation). The loader will iterate the map keys in round‑robin order.

- `SourceCursorStoreInterface`
  - `getLastStoredSourceEventId(string $sourceName): ?int`
  - `setLastStoredSourceEventId(string $sourceName, int $sourceEventId): void`

- `SourceLeaseManagerInterface`
  - `tryAcquireLease(string $sourceName, string $ownerId, int $ttlMs): bool` — attempt to acquire lease; returns true if successful.
  - `renewLease(string $sourceName, string $ownerId, int $ttlMs): bool` — renew an existing lease held by `ownerId`.
  - `releaseLease(string $sourceName, string $ownerId): void` — release the lease if owned by `ownerId`.
  - `getLeaseOwner(string $sourceName): ?string`

- `EventLoaderInterface`
  - `runLoop(): void` — start the infinite loop processing (blocking call); honor graceful shutdown.
  - `runOnce(): void` — perform a single round of processing over all sources (useful for tests).
  - `stop(): void` — request graceful shutdown (loader should finish current batch within timeout and exit).

- `ClockInterface`
  - `now(): int` — return unix‑ms timestamp (used for testing and scheduling).

- `LoggerInterface`
  - typical `info(string $msg, array $ctx = [])`, `warn(...)`, `error(...)` semantics (PSR‑3 compatible recommended).

8.1.2 Exceptions and error semantics

Define a small set of domain exceptions so calling code (the loader) can decide retries vs. skips:

- `TransientEventSourceException` — transient failure fetching from a source (network timeout, 5xx). Loader MAY retry according to retry policy.
 - `TransientEventSourceException` — transient failure fetching from a source (network timeout, 5xx). The reference loader SHOULD log the incident and skip the source for the current iteration; automatic retry/backoff is out of scope and not required.
 - `PermanentEventSourceException` — permanent failure (4xx unrecoverable); loader MUST log and skip source for this iteration.
 - `EventStorageException` — permanent storage failure; considered critical (loader should log and may abort depending on policy).
 - `TransientStorageException` — temporary storage problem; the reference loader SHOULD log and skip (automatic retry is out of scope).
- `LeaseAcquireException` / `LeaseReleaseException` — problems interacting with coordination store; loader should log and skip/continue accordingly.

Implementations should map lower‑level client/library exceptions to these domain exceptions at the adapter boundary.

Implementation note: these abstractions will be defined as PHP `interface`s and modeled as Symfony services in the reference implementation. The reference implementation MUST provide only the PHP `interface` definitions and Symfony wiring (service configuration); concrete infra adapters/clients (HTTP, gRPC, queue clients) and storage backends are explicitly OUT OF SCOPE for the first version and do not need to be implemented. Use Composer for dependency management and PSR‑4 autoloading.

 The loader will perform exactly one fetch operation per acquired lease.

### 8.2 Coordination mechanism

The task requirements effectively require a centralized coordination mechanism per source.

Minimum recommendation:

- for each source, there is a distributed state record containing at least:
  - the last successfully stored `lastStoredSourceEventId`
  - the point in time when the next request to that source is allowed
  - information that the source is currently leased by one loader instance

Before making a network call, one instance must atomically:

- acquire a lease on the source
- confirm that at least 200 ms have passed since the previous request
- lock the processing range so that another instance does not request the same event segment

Without such atomic coordination, it is not possible to satisfy the requirement that the same event is never transferred over the network more than once.

Recommended lease implementation

We recommend using Redis for per-source leases. Redis provides native TTL semantics, low-latency atomic primitives (e.g. `SET NX PX`) and well-known patterns for short-lived leases and expiries — properties that match the task requirement for globally enforced, time-bounded per-source leasing. Store one key per source (for example `lock:{sourceName}`) containing the owner token and acquisition timestamp; choose a TTL larger than the expected fetch+store window as a safety margin. Treat the lock as authoritative only for ownership and throttling; always validate the authoritative last-stored event ID from persistent storage before making the remote fetch. Redis adds operational complexity versus a single SQL row, but its expiry/TTL semantics and low latency make it the preferred choice for short-lived coordination in this design.

Concrete Redis pattern (decided):

- Coordination store: **Redis** is the chosen coordination store for the reference implementation.
- Key patterns:
  - `source:{name}` — per-source metadata hash with fields: `nextCallAfter` (unix‑ms), `lastStoredSourceEventId` (advisory). The reference implementation expects that `EventSourceRegistryInterface::getSources()` provides values that are directly JSON-serializable into this key (i.e., the registry value may be `json_encode()`d and stored as the value of `source:{name}` without transformation). Example shape:

    {
      "nextCallAfter": 1712678400000,
      "lastStoredSourceEventId": 12345,
      "description": "Optional human-friendly text"
    }
  - `lock:{name}` — per-source lease key holding minimal information: `owner_id` (owner token) and `acquired_at` (unix‑ms). Acquire with atomic `SET NX PX` semantics.
- Semantics:
  - Acquire `lock:{name}` using `SET NX PX <ttl_ms>`; `ttl_ms` derived from `max_call_duration_ms` with a safety margin. While TTL should be large enough to cover a normal fetch+store cycle, loaders MUST explicitly release `lock:{name}` (delete the key) immediately after successfully updating the checkpoint and related metadata — TTL is only a safety fallback for crash scenarios.
  - While holding the lock the loader may read/write `source:{name}` as optimization hints, but must always read the authoritative checkpoint from persistent storage before fetching.
  - If `nextCallAfter` (from `source:{name}`) indicates the source is not yet allowed, the holder may either wait while renewing the lease or release and skip; prefer skipping and letting round‑robin revisit later to avoid long-held locks.
  - Release the lock by deleting `lock:{name}` or let TTL expire. During normal operation, locks should be deleted when done so the next loader can take over. As safety, the lock will expire after TTL.

These changes resolve question P1 by mandating Redis for per‑source coordination in the reference design.

Per-source metadata + per-source lock (selected approach)

- Assumption: the number of sources is moderate and source definitions are relatively stable compared with locks. Each source is represented in Redis (or an equivalent coordination store) as `source:{name}` with fields such as:
  - `nextCallAfter` (unix-ms timestamp when the next request for this source is allowed)
  - `lastStoredSourceEventId` (advisory last observed sourceEventId)

- Each source also has a per-source lease key `lock:{name}`. A loader that successfully acquires `lock:{name}` becomes the owner and is authorized to modify `source:{name}`.

- Loader behavior (round‑robin + per‑source lock):
  1. Iterate the local ordered list of sources in round‑robin order.
  2. For each candidate source attempt to acquire `lock:{name}` (atomic `SET NX PX ownerToken` or equivalent).
  3. If the lock is not acquired, skip the source and continue.
  4. If the lock is acquired, read `source:{name}` (note: its values are advisory) and then read the authoritative last-stored `sourceEventId` from persistent storage; use the DB value as the source-of-truth `lastStoredSourceEventId` before making the remote fetch.
 4.5. The loader MUST read any per-source metadata from the value returned by `getSources()` (the per-source JSON object) and may write updated metadata back to `source:{name}` by serializing that object. This keeps the registry authoritative for initial per-source configuration while allowing runtime metadata updates to be persisted.
  5. If `nextCallAfter` (or `nextAllowedAt`) indicates the call window has not yet opened, wait (or sleep until allowed) while holding or renewing the lease as needed.
  6. Make the remote fetch using the authoritative `lastStoredSourceEventId`, store events, then update `source:{name}.nextCallAfter` (e.g. `now + throttle_ms`), `source:{name}.lastStoredSourceEventId`, and set the next allowed time (enforcing the `throttle_ms` global throttle). Release the lock (or let the TTL expire) and continue.

- Notes:
  - `lock:{name}` is the coordination primitive enforcing exclusivity and throttle; `source:{name}` is metadata and an optimization hint. Always validate against persistent storage before fetching.
  - Choose TTLs and renewal behavior so that locks do not block progress if a loader crashes; prefer letting TTL expire rather than force-deleting locks.

Configuration assumptions

- The loader must expose configuration parameters that control timing behavior. In particular:
  - `max_call_duration_ms` — the maximum time the loader expects a single remote fetch+store cycle to take (default: 5 minutes). Lock TTLs should be derived from this parameter with a safety margin.
  - `throttle_ms` — the per-source global throttle interval (default: 200 ms).

- These parameters are provided at the loader level (instance config). The spec notes that these could instead be stored as global configuration in a central store, but that would increase operational complexity (coordination, updates, and versioning) and is left as an optional extension. The implementation will treat the loader-level config as authoritative for TTL and throttle decisions.

### 8.3 Minimum loader flow

One iteration over a single source should look like this:

1. Select the next source in round-robin order.
2. Attempt to acquire a lease for that source.
3. If the lease is not available or 200 ms have not yet passed, skip the source.
4. Read the current checkpoint for the source.
4a. Verify checkpoint against storage: after acquiring the lease and reading the lease/checkpoint value for the source, call `EventStorageInterface::fetchLastSourceEventId(sourceName)` (or equivalent authoritative query) to obtain the authoritative last-stored `sourceEventId` and use that DB value as the source-of-truth `lastStoredSourceEventId` for the upcoming fetch. If the DB value is greater than the lease/checkpoint value, start from the DB value. If it is unexpectedly smaller, log a warning and use the DB value as the authoritative start point.
5. Call the remote source with `lastStoredSourceEventId`.
6. If the call fails, log the error and release the lease.
7. If a batch of events is returned, store it in the storage layer.
8. After successful storage, update the checkpoint to the last stored event ID.
9. Record the new point in time from which the next request is allowed.
10. Release the lease and continue. The loader MUST explicitly release the lease by deleting the `lock:{name}` key immediately after updating the checkpoint and related metadata; it must not rely on the TTL to free the lock during normal operation.

### Lease timeouts and operational assumption

Operational assumption: duplicate network retrievals remain possible in crash-and-recovery windows (for example, after a loader fetches events but crashes before checkpointing). The design therefore treats duplicate prevention as a primary goal during normal concurrent operation, while acknowledging that rare duplicates may still occur. Implementation will include pragmatic checks, sensible lease TTLs, and bounded fetch/store timeouts to minimize such duplicates as much as reasonably possible.

## 9. Main product and technical decisions to confirm

These decisions are not fully determined by the task text and should be confirmed before implementation:

- where the distributed coordination state will be stored: SQL database, Redis, or another locking/store mechanism
 - where the distributed coordination state will be stored: Resolved — Redis chosen for the reference implementation (see 8.2)
- whether round-robin order must be strictly deterministic globally, or whether it is sufficient for each instance to iterate locally over the same list of sources
- what exactly "skip source" means on failure: Resolved — the reference implementation will **log and skip immediately**; no per-source backoff or retry/backoff is required in the loader. Implementations MAY add backoff if desired, but the reference loader must not implement it.
- whether the loader should support graceful shutdown
- whether logging should be purely technical or include domain-specific error codes
- whether the specification should explicitly state that the 1000-event batch limit is only a retrieval contract and must not be interpreted as a throughput or scaling target


## 10. Open questions for clarification

Below is the list of questions that should reasonably be clarified before implementation. If answers are not available, we will need to document explicit assumptions.

### P1 Which mechanism may we use for distributed coordination?

The task says "Any", but for the actual specification we should confirm the preferred choice:

### P1 Distributed coordination (resolved)

Resolved for the reference implementation: **Redis** with per‑source metadata (`source:{name}`) and per‑source lease keys (`lock:{name}`) as described in Section 8.2. This choice affects interface expectations and atomicity semantics (see 8.2 for key patterns and lease semantics).

### P2 Must the no-duplicate-retrieval rule apply for the entire lifetime of the system, or only during concurrent loader execution?

The text says "during the system's operation", which suggests a strict interpretation across the full system runtime. We should confirm whether that means:

- never re-fetch an event that was already transferred over the network once, even after an instance restart
- or only avoid conflicts between parallel instances during the same operating period

Decision: discussed different scenarios

### P3 May we assume that storage is idempotent by `(sourceName, sourceEventId)`?

Although the task says we do not need to implement storage, it is important to know whether the expectation is:

- strictly exactly-once storage
- or only exactly-once network retrieval, with potentially idempotent writes

Decision: implementation level detail, we will assume yes

### P4 What exactly does round-robin mean in a distributed scenario?

We should confirm whether it is enough for each instance to iterate through the source list locally in round-robin order, or whether one globally coordinated round-robin sequence is expected.

Without that answer, there are multiple valid interpretations.

Decision: local round-robin + global locking logic in Redis

### P5 How should a permanently unavailable source be treated?

The task says "skip it and log". Resolved for the reference implementation: **log and skip immediately**. The loader MUST not implement per-source retry/backoff — it should continue to the next source in the same cycle. Implementations MAY introduce backoff or rate-limiting outside the reference loader, but that behavior is explicitly out of scope for the reference implementation.

### P6 What behavior is acceptable if the process stops during batch handling?

Example: events have been fetched, but the process crashes before storage or before checkpoint update. We should confirm whether it is acceptable for the coordination lease to expire and for another instance to later repeat the same attempt, or whether stricter semantics are expected.

Decision: assume it is acceptable to fetch events again

### P7 Should we define a minimal observability set in the first version?

For example:

- number of successful batches per source
- number of failures per source
- time of the last successful retrieval

The task does not require this, but it is useful for operating the system.

Decision: out of scope

### P8 Should dynamic addition and removal of sources be supported?

The task assumes multiple sources, but does not say whether they are:

- statically configured at process startup
- or allowed to change during runtime

Decision: out of scope

### P9 What should we state in the specification about performance and payload size?

Although optimization is not the priority, it is useful to confirm whether large event payloads are expected, because that affects whether a batch should be stored as a whole or streamed event by event.

Decision: out of scope

## 11. Recommended initial assumptions if answers are not available

If implementation needs to start before all questions are answered, the following initial assumptions should be documented:

- distributed coordination uses a central persistent store with atomic updates
- a conflict is defined as any repeated network retrieval of the same `(sourceName, sourceEventId)`
- storage is considered reliable and sufficiently idempotent for the expected execution model
- round-robin is deterministic locally per instance, while exclusivity is enforced through a per-source lease mechanism
- on source failure, the loader logs the incident and continues without crashing the process
- the checkpoint advances only after successful batch storage

- Repository deliverables: include a `composer.json` targeting PHP >= 8.1, a minimal Symfony project skeleton or instructions to scaffold it, PSR‑4 autoloading, an example Symfony console command to start the loader, and a `README.md` with run/test instructions.

## 12. Recommended next step

Before technical design and implementation, at least questions P1, P2, and P4 should be confirmed, because they have the biggest impact on the architecture and on the provability of the "no duplicate network transport" behavior.