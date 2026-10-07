# GetOrganizerEvents

Gets the events of the logged-in user for the "My events" page
(`GET /organizer/events`).

The page shows only the events of the user, with all statuses. Admins also see only
their own events on this page.

The Action reads the events with one query, sorted by start time. Then it splits them:

- "Upcoming": the events that have not started, earliest first.
- "Past": the events that have started, most recent first. An event that starts now
  has started.

For each event, the page shows the title, the status, the start time and the booked
seats (capacity − available seats).

## The events are shown

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Controller as OrganizerEventController
    participant Action as GetOrganizerEvents
    participant DB as PostgreSQL

    Browser->>Middleware: GET /organizer/events
    Middleware->>Middleware: Check login
    Middleware->>Controller: index(request)
    Controller->>Action: handle(user)
    Action->>DB: Read the events of the user, sorted by start time
    DB-->>Action: Event rows
    Action->>Action: Split into upcoming and past events
    Action-->>Controller: Page data
    Controller-->>Browser: 200, Inertia page organizer/events/index
```

## The person is not logged in

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware

    Browser->>Middleware: GET /organizer/events
    Middleware->>Middleware: Check login
    Middleware-->>Browser: Redirect to /login
```
