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

## 4. Scope of the first version

In scope:

- the domain model required for the loader
- an interface for the event source client
- an interface for event storage
- an interface or service for progress coordination and per-source locking
- the main event loader running in an infinite loop
- a basic error-handling strategy and retry behavior at the loop level
- the ability to test the loader with mocks and stubs

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
- supports retrieval of events strictly greater than a given event ID
- returns at most 1000 events per request

### 5.2 Event

For the purposes of the core, it is sufficient to assume:

- `sourceName` identifies the source
- `eventId` is unique within a source
- `eventId` grows monotonically over time
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

For each source, the system must retrieve events with the condition `eventId > lastStoredEventId`.

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
  - retrieves events from a specific source after the given `lastEventId`
- `EventStorageInterface`
  - durably stores a batch of events
- `EventSourceRegistryInterface`
  - provides the list of configured sources and their order for round-robin processing
- `SourceCursorStoreInterface`
  - reads and writes the last successfully stored event ID per source
- `SourceLeaseManagerInterface`
  - acquires distributed processing rights for a source and enforces the 200 ms interval
 - may include an advisory `hintLastEventId` in the lease; this is a hint and must be validated against storage before performing a fetch
- `EventLoaderInterface`
  - starts the main loop or a single loader iteration
- `ClockInterface`
  - abstracts time for testing throttling logic
- `LoggerInterface`
  - logs errors and source unavailability

### 8.2 Coordination mechanism

The task requirements effectively require a centralized coordination mechanism per source.

Minimum recommendation:

- for each source, there is a distributed state record containing at least:
  - the last successfully stored `lastStoredEventId`
  - the point in time when the next request to that source is allowed
  - information that the source is currently leased by one loader instance

Before making a network call, one instance must atomically:

- acquire a lease on the source
- confirm that at least 200 ms have passed since the previous request
- lock the processing range so that another instance does not request the same event segment

Without such atomic coordination, it is not possible to satisfy the requirement that the same event is never transferred over the network more than once.

### 8.3 Minimum loader flow

One iteration over a single source should look like this:

1. Select the next source in round-robin order.
2. Attempt to acquire a lease for that source.
3. If the lease is not available or 200 ms have not yet passed, skip the source.
4. Read the current checkpoint for the source.
4a. Verify checkpoint against storage: after acquiring the lease and reading the lease/checkpoint value for the source, read the authoritative last-stored event ID from persistent storage (for example: `SELECT id FROM events WHERE source = ? ORDER BY id DESC LIMIT 1`) and use that DB value as the source-of-truth `lastEventId` for the upcoming fetch. If the DB value is greater than the lease/checkpoint value, start from the DB value. If it is unexpectedly smaller, log a warning and use the DB value as the authoritative start point.
5. Call the remote source with `lastEventId`.
6. If the call fails, log the error and release the lease.
7. If a batch of events is returned, store it in the storage layer.
8. After successful storage, update the checkpoint to the last stored event ID.
9. Record the new point in time from which the next request is allowed.
10. Release the lease and continue.

### Lease timeouts and operational assumption

Operational assumption: duplicate network retrievals remain possible in crash-and-recovery windows (for example, after a loader fetches events but crashes before checkpointing). The design therefore treats duplicate prevention as a primary goal during normal concurrent operation, while acknowledging that rare duplicates may still occur. Implementation will include pragmatic checks, sensible lease TTLs, and bounded fetch/store timeouts to minimize such duplicates as much as reasonably possible.

## 9. Main product and technical decisions to confirm

These decisions are not fully determined by the task text and should be confirmed before implementation:

- where the distributed coordination state will be stored: SQL database, Redis, or another locking/store mechanism
- whether round-robin order must be strictly deterministic globally, or whether it is sufficient for each instance to iterate locally over the same list of sources
- what exactly "skip source" means on failure: immediately continue to the next source without backoff, or introduce a minimal per-source backoff
- whether the loader should support graceful shutdown
- whether logging should be purely technical or include domain-specific error codes
- whether the specification should explicitly state that the 1000-event batch limit is only a retrieval contract and must not be interpreted as a throughput or scaling target


## 10. Open questions for clarification

Below is the list of questions that should reasonably be clarified before implementation. If answers are not available, we will need to document explicit assumptions.

### P1 Which mechanism may we use for distributed coordination?

The task says "Any", but for the actual specification we should confirm the preferred choice:

- an SQL table with row-level locking
- Redis with lease/lock records
- something else

This directly affects interface design and atomicity semantics.

### P2 Must the no-duplicate-retrieval rule apply for the entire lifetime of the system, or only during concurrent loader execution?

The text says "during the system's operation", which suggests a strict interpretation across the full system runtime. We should confirm whether that means:

- never re-fetch an event that was already transferred over the network once, even after an instance restart
- or only avoid conflicts between parallel instances during the same operating period

### P3 May we assume that storage is idempotent by `(sourceName, eventId)`?

Although the task says we do not need to implement storage, it is important to know whether the expectation is:

- strictly exactly-once storage
- or only exactly-once network retrieval, with potentially idempotent writes

### P4 What exactly does round-robin mean in a distributed scenario?

We should confirm whether it is enough for each instance to iterate through the source list locally in round-robin order, or whether one globally coordinated round-robin sequence is expected.

Without that answer, there are multiple valid interpretations.

### P5 How should a permanently unavailable source be treated?

The task says "skip it and log", but it is not clear whether:

- it should be retried again in the very next cycle
- a backoff should be introduced
- there should be a maximum error log frequency

### P6 What behavior is acceptable if the process stops during batch handling?

Example: events have been fetched, but the process crashes before storage or before checkpoint update. We should confirm whether it is acceptable for the coordination lease to expire and for another instance to later repeat the same attempt, or whether stricter semantics are expected.

### P7 Should we define a minimal observability set in the first version?

For example:

- number of successful batches per source
- number of failures per source
- time of the last successful retrieval

The task does not require this, but it is useful for operating the system.

### P8 Should dynamic addition and removal of sources be supported?

The task assumes multiple sources, but does not say whether they are:

- statically configured at process startup
- or allowed to change during runtime

### P9 What should we state in the specification about performance and payload size?

Although optimization is not the priority, it is useful to confirm whether large event payloads are expected, because that affects whether a batch should be stored as a whole or streamed event by event.

## 11. Recommended initial assumptions if answers are not available

If implementation needs to start before all questions are answered, the following initial assumptions should be documented:

- distributed coordination uses a central persistent store with atomic updates
- a conflict is defined as any repeated network retrieval of the same `(sourceName, eventId)`
- storage is considered reliable and sufficiently idempotent for the expected execution model
- round-robin is deterministic locally per instance, while exclusivity is enforced through a per-source lease mechanism
- on source failure, the loader logs the incident and continues without crashing the process
- the checkpoint advances only after successful batch storage

## 12. Recommended next step

Before technical design and implementation, at least questions P1, P2, and P4 should be confirmed, because they have the biggest impact on the architecture and on the provability of the "no duplicate network transport" behavior.