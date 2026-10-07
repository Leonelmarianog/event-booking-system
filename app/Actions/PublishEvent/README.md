# PublishEvent

Publishes a draft event (`POST /events/{event}/publication`). After this, everyone can
see the event (BR-E14).

Before the controller runs:

- The `auth` middleware sends visitors to the login page.
- The `event-writes` rate limiter allows 20 event writes each minute for each user.
- The `publish` ability of `EventPolicy` checks the person. Only the organizer can
  publish the event (BR-E9, BR-A3). A person who cannot see the event gets 404, so that
  hidden events stay unknown. Other refusals give 403.

`Event::publish()` checks the event (BR-E10). The event must be a draft
(`EventStatus::canTransitionTo()`), and its start time must be in the future.
Otherwise the model throws `InvalidStateTransition` or `EventHasStarted`. The handler
in `bootstrap/app.php` turns them into a redirect back with an error toast.

The Action makes one update, with no transaction. If two publish requests come at the
same time, both set the same status.

## The event is published

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant Controller as EventPublicationController
    participant Action as PublishEvent
    participant Event
    participant DB as PostgreSQL

    Browser->>Middleware: POST /events/{event}/publication
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row
    Middleware->>Policy: publish(user, event)
    Policy-->>Middleware: Allow
    Middleware->>Controller: store(event)
    Controller->>Action: handle(event)
    Action->>Event: publish()
    Event-->>Action: Status published, publication time set
    Action->>DB: Update the event
    Action-->>Controller: Event
    Controller-->>Browser: Redirect to /events/{event}, toast "Event published."
```

## The event is not a draft

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Controller as EventPublicationController
    participant Action as PublishEvent
    participant Event
    participant Handler as Exception handler

    Browser->>Middleware: POST /events/{event}/publication
    Middleware->>Middleware: Check login, rate limit and policy
    Middleware->>Controller: store(event)
    Controller->>Action: handle(event)
    Action->>Event: publish()
    Event-->>Action: InvalidStateTransition
    Action-->>Handler: InvalidStateTransition
    Handler-->>Browser: Redirect back, error toast
```

## The event has started

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Controller as EventPublicationController
    participant Action as PublishEvent
    participant Event
    participant Handler as Exception handler

    Browser->>Middleware: POST /events/{event}/publication
    Middleware->>Middleware: Check login, rate limit and policy
    Middleware->>Controller: store(event)
    Controller->>Action: handle(event)
    Action->>Event: publish()
    Event-->>Action: EventHasStarted
    Action-->>Handler: EventHasStarted
    Handler-->>Browser: Redirect back, error toast
```

## The person is not the organizer

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant DB as PostgreSQL

    Browser->>Middleware: POST /events/{event}/publication
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row (published event of another user)
    Middleware->>Policy: publish(user, event)
    Policy-->>Middleware: Deny
    Middleware-->>Browser: 403 page
```

## The person cannot see the event

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant DB as PostgreSQL

    Browser->>Middleware: POST /events/{event}/publication
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row (draft or cancelled event of another user)
    Middleware->>Policy: publish(user, event)
    Policy-->>Middleware: Deny as not found
    Middleware-->>Browser: 404 page
```

## The user made too many event writes

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Redis

    Browser->>Middleware: POST /events/{event}/publication
    Middleware->>Redis: Count the event writes of the user
    Redis-->>Middleware: Limit exceeded
    Middleware-->>Browser: Redirect back with an error toast (plain requests get 429)
```
