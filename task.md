# Test Task: Event Loading Mechanism Design

## Task overview

Your task is to design the core for a future event loading system, which will collect events from multiple sources into a centralized storage. You do not need to build a full-fledged application. Instead, you need to design the key interfaces and implement the core method responsible for event loading.

The system should allow multiple instances of loaders to run simultaneously, ensuring that no conflicts occur during parallel execution (even if they are running on different servers). Specifically, it is important to ensure that the same event is not requested from a source more than once.

This task does not have a single correct solution and gives you the freedom to choose your approach. The goal is to assess not only your PHP knowledge but also your architectural thinking and ability to make technical decisions.

## Key implementation steps

1. Determine the necessary interfaces for implementing the system and design them. These interfaces should cover the following aspects:
   - Retrieving events from a remote source.
   - Storing retrieved events in a database.
   - The main event loader that coordinates the process of retrieving and storing events.
2. Implement the primary interface responsible for the main event loading flow and coordinating the processes. Implementing other interfaces, including database storage, is not required.
3. Providing a test demonstrating its functionality is optional but would be an advantage. You can use mocks and stubs for interfaces that are not implemented.

## Requirements

### 1. Error Handling

- Your system should correctly handle errors that occur when requesting events, including network failures and server errors.

### 2. Loading Constraints

- Event sources can provide up to 1,000 events per request.
- The loader should run in an infinite loop, querying event sources in a round-robin fashion.
- There must be at least a 200 ms interval between any two consecutive requests to the same event source, regardless of which loader instance initiates the request.

### 3. Parallel Execution

- When multiple loader instances are running, the same event must not be requested again.
- Loaders running in parallel may be operating on different servers.

## Clarifications

All events are immutable and have a unique identifier (of type `int`) within a single source. The later an event occurs, the larger its identifier value. The event source endpoint returns new events as a list sorted by ID, allowing the client to specify the last known event ID. That is, the source behaves similarly to a database query like:

```sql
SELECT * FROM events WHERE id > ? ORDER BY id LIMIT 1000
```

Each event source is a service that provides data over a network and has a unique name.

### What database should be used?

- You do not need to use a database, as you are not required to implement the storage interface. Assume that the database is fault-tolerant, and if a method completes without throwing an exception, the data is reliably stored. You don't need to catch the database exceptions.

### Should caching, scaling, or optimization be considered?

- No, write the minimal code necessary to meet the requirements.

### What about frameworks?

- You may use Symfony or write in plain PHP. You may use any packages available on Packagist. The PHP version should be 8.1 or later.

### What locking mechanisms can be used?

- Any.

### What is the format of the event retrieval?

- Interfaces should be designed to be independent of the protocol or message format used for network communication. Implementations (which do not need to be written as part of this task) will handle formats and protocols.

### What should be done if an event source fails?

- Simply skip it and log that the source is unavailable.

### How is conflict-free execution determined?

- If an event from a source with a specific identifier is transported over the network more than once during the system's operation, a conflict has occurred.

### If I decide to write a test, what should it include?

- It is up to you. The main requirement is to provide instructions on how to deploy and run it.

### In what format should the result be provided?

- It can be a zip archive of the project or a public repository.
