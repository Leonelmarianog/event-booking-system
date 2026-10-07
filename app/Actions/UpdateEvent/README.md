# UpdateEvent

Updates an event (`PUT /events/{event}`). The edit page is `GET /events/{event}/edit`.

Before the controller runs:

- The `auth` middleware sends visitors to the login page.
- The `event-writes` rate limiter allows 20 event writes each minute for each user.
- The `update` ability of `EventPolicy` checks the person and the event. Only the
  organizer can edit the event (BR-E4, BR-A3), and only when the event is not cancelled
  and has not started (BR-E5). A person who cannot see the event gets 404, so that
  hidden events stay unknown. Other refusals give 403.
- `UpdateEventRequest` validates the data with the same rules as the create form:
  the capacity is from 1 to 10,000 (BR-E2) and the start time is in the future (BR-E3).

The Action locks the event row in a transaction. `Event::changeCapacity()` changes the
available seats by the same number as the capacity (BR-E7, BR-E8). The new capacity
must not be less than the booked seats (BR-E6). Otherwise the model throws
`CapacityBelowBookedSeats`, and the transaction rolls back. The status of the event
does not change.

The handler in `bootstrap/app.php` turns each domain exception into a redirect back,
with an error toast and an error under the field. A JSON request gets 422 with the
message.

## The event is updated

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant Request as UpdateEventRequest
    participant Controller as EventController
    participant Action as UpdateEvent
    participant Event
    participant DB as PostgreSQL

    Browser->>Middleware: PUT /events/{event}
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row
    Middleware->>Policy: update(user, event)
    Policy-->>Middleware: Allow
    Middleware->>Request: Validate the data
    Request-->>Middleware: Data is valid
    Middleware->>Controller: update(request, event)
    Controller->>Action: handle(event, data)
    Action->>DB: Begin, lock the event row
    DB-->>Action: Event row
    Action->>Event: changeCapacity(capacity)
    Event-->>Action: Capacity and available seats changed
    Action->>DB: Update the event, commit
    Action-->>Controller: Event
    Controller-->>Browser: Redirect to /events/{event}, toast "Event updated."
```

## The capacity is less than the booked seats

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Controller as EventController
    participant Action as UpdateEvent
    participant Event
    participant DB as PostgreSQL
    participant Handler as Exception handler

    Browser->>Middleware: PUT /events/{event}
    Middleware->>Middleware: Check login, rate limit, policy and data
    Middleware->>Controller: update(request, event)
    Controller->>Action: handle(event, data)
    Action->>DB: Begin, lock the event row
    DB-->>Action: Event row
    Action->>Event: changeCapacity(capacity)
    Event-->>Action: CapacityBelowBookedSeats
    Action->>DB: Roll back
    Action-->>Handler: CapacityBelowBookedSeats
    Handler-->>Browser: Redirect back, error under "Seats" and error toast
```

## The data is not valid

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Request as UpdateEventRequest

    Browser->>Middleware: PUT /events/{event}
    Middleware->>Middleware: Check login, rate limit and policy
    Middleware->>Request: Validate the data
    Request-->>Browser: Redirect back with the field errors
```

## The person cannot edit the event

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant DB as PostgreSQL

    Browser->>Middleware: PUT /events/{event}
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row
    Middleware->>Policy: update(user, event)
    Policy-->>Middleware: Deny (not the organizer, cancelled or started)
    Middleware-->>Browser: 403 page
```

## The person cannot see the event

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant DB as PostgreSQL

    Browser->>Middleware: PUT /events/{event}
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row (draft or cancelled event of another user)
    Middleware->>Policy: update(user, event)
    Policy-->>Middleware: Deny as not found
    Middleware-->>Browser: 404 page
```

## The user made too many event writes

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Redis

    Browser->>Middleware: PUT /events/{event}
    Middleware->>Redis: Count the event writes of the user
    Redis-->>Middleware: Limit exceeded
    Middleware-->>Browser: Redirect back with an error toast (plain requests get 429)
```
