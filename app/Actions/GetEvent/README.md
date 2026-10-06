# GetEvent

Gets the data for the page of one event (`GET /events/{event}`).

The route checks the `view` ability of `EventPolicy` before the controller runs. A
person who cannot see the event gets a 404 response, the same as for an event that does
not exist. Thus, nobody can find hidden events by trying event IDs.

| Event status | Who can see it                                              | Rules         |
| ------------ | ----------------------------------------------------------- | ------------- |
| Published    | Everyone, also visitors                                     | BR-E14        |
| Draft        | The organizer and admins                                    | BR-E15, BR-A4 |
| Cancelled    | The organizer and admins (attendees after M4 adds bookings) | BR-E16, BR-A4 |

The middleware step includes the route model binding: Laravel reads the event row from
PostgreSQL before the policy check.

## The event is shown

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant Controller as EventController
    participant Action as GetEvent
    participant DB as PostgreSQL

    Browser->>Middleware: GET /events/{event}
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row
    Middleware->>Policy: view(user or no user, event)
    Policy-->>Middleware: Allow
    Middleware->>Controller: show(event)
    Controller->>Action: handle(event)
    Action->>DB: Read the name of the organizer
    DB-->>Action: Organizer
    Action-->>Controller: Page data
    Controller-->>Browser: 200, Inertia page events/show
```

## The person cannot see the event

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant DB as PostgreSQL

    Browser->>Middleware: GET /events/{event}
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row (draft or cancelled)
    Middleware->>Policy: view(user or no user, event)
    Policy-->>Middleware: Deny as not found
    Middleware-->>Browser: 404 page
```

## The event does not exist

The route accepts only numeric IDs. For an ID that is not a number, for example
`/events/abc`, the router gives the 404 page before it reads the database.

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant DB as PostgreSQL

    Browser->>Middleware: GET /events/{event}
    Middleware->>DB: Read the event
    DB-->>Middleware: No row
    Middleware-->>Browser: 404 page
```
