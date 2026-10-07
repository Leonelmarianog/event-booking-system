# DeleteEvent

Deletes a draft event (`DELETE /events/{event}`). After the delete, the user sees the
"My events" page.

Before the controller runs:

- The `auth` middleware sends visitors to the login page.
- The `event-writes` rate limiter allows 20 event writes each minute for each user.
- The `delete` ability of `EventPolicy` checks the person and the event. Only the
  organizer can delete the event, and only when it is a draft (BR-E17). A person who
  cannot see the event gets 404, so that hidden events stay unknown. Other refusals
  give 403.

The Action calls `Event::ensureCanBeDeleted()` before the delete. The policy already
refuses events that are not drafts, so this check is a last line of defense. If it
fails, the model throws `InvalidStateTransition`, and the handler in
`bootstrap/app.php` shows an error toast.

The Action makes one delete, with no transaction. A draft has no bookings, so no other
rows depend on it. The start time does not matter: a draft that has started can be
deleted.

## The event is deleted

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant Controller as EventController
    participant Action as DeleteEvent
    participant Event
    participant DB as PostgreSQL

    Browser->>Middleware: DELETE /events/{event}
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row
    Middleware->>Policy: delete(user, event)
    Policy-->>Middleware: Allow
    Middleware->>Controller: destroy(event)
    Controller->>Action: handle(event)
    Action->>Event: ensureCanBeDeleted()
    Event-->>Action: The event is a draft
    Action->>DB: Delete the event
    Action-->>Controller: Done
    Controller-->>Browser: Redirect to /organizer/events, toast "Event deleted."
```

## The person cannot delete the event

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant DB as PostgreSQL

    Browser->>Middleware: DELETE /events/{event}
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row
    Middleware->>Policy: delete(user, event)
    Policy-->>Middleware: Deny (not the organizer, or not a draft)
    Middleware-->>Browser: 403 page
```

## The person cannot see the event

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant DB as PostgreSQL

    Browser->>Middleware: DELETE /events/{event}
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row (draft or cancelled event of another user)
    Middleware->>Policy: delete(user, event)
    Policy-->>Middleware: Deny as not found
    Middleware-->>Browser: 404 page
```

## The user made too many event writes

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Redis

    Browser->>Middleware: DELETE /events/{event}
    Middleware->>Redis: Count the event writes of the user
    Redis-->>Middleware: Limit exceeded
    Middleware-->>Browser: Redirect back with an error toast (plain requests get 429)
```
