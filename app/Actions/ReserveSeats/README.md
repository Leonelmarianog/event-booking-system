# ReserveSeats

Books seats of an event for the user (`POST /events/{event}/bookings`). The form is on
the event page.

Before the controller runs:

- The `auth` middleware sends visitors to the login page (BR-B1).
- The `bookings` rate limiter allows 10 booking requests each minute for each user.
- The `view` ability of `EventPolicy` checks that the user can see the event. A user
  who cannot see the event gets 404, so that hidden events stay unknown.
- `StoreBookingRequest` validates the quantity: an integer from 1 to 4 (BR-B5).

The Action starts a transaction and locks the event row. Parallel requests for the same
event wait for the lock, one after the other. Thus, two users never get the same last
seat (BR-B14), and two requests of the same user cannot make two bookings (BR-B4).

`Event::reserve()` checks the rules on the locked row, in this order:

1. The event is published (BR-B2). Otherwise: `EventNotBookable`.
2. The event has not started (BR-B2). Otherwise: `EventNotBookable`.
3. The user is not the organizer (BR-B3). Otherwise: `EventNotBookable`.
4. The user has no confirmed booking for the event (BR-B4). Otherwise: `AlreadyBooked`.
   A cancelled booking does not count (BR-B12).
5. The quantity is not more than the available seats (BR-B2, BR-B6). Otherwise:
   `NotEnoughSeats`.

Then the model decreases the available seats (BR-B7) and gives a new confirmed booking.
The Action saves the event and the booking. On insert, the model gives the booking a
unique ULID reference (BR-B8). If the model throws an exception, the transaction rolls back. The
handler in `bootstrap/app.php` turns the exception into a redirect back with an error
toast. For `NotEnoughSeats`, the error also shows under the "Seats" field.

After the saves, the Action sends the `BookingConfirmed` notification to the attendee
(BR-N1). The notification is queued and marked "after commit", so the queue keeps the
job until the transaction commits. On a rollback, the queue drops the job and no email
goes out (BR-N6). The worker sends the email. It tries 3 times, with a wait of 10 and
then 60 seconds between the tries.

## The seats are booked

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Policy as EventPolicy
    participant Request as StoreBookingRequest
    participant Controller as BookingController
    participant Action as ReserveSeats
    participant Event
    participant DB as PostgreSQL
    participant Queue

    Browser->>Middleware: POST /events/{event}/bookings
    Middleware->>Middleware: Check login and rate limit
    Middleware->>DB: Read the event
    DB-->>Middleware: Event row
    Middleware->>Policy: view(user, event)
    Policy-->>Middleware: Allow
    Middleware->>Request: Validate the quantity
    Request-->>Middleware: Quantity is valid
    Middleware->>Controller: store(request, event)
    Controller->>Action: handle(event, user, quantity)
    Action->>DB: Begin, lock the event row
    DB-->>Action: Event row
    Action->>Event: reserve(user, quantity)
    Event->>DB: Read the confirmed booking of the user
    DB-->>Event: No booking
    Event-->>Action: Available seats decreased, new confirmed booking
    Action->>DB: Update the event, insert the booking
    Action->>Queue: BookingConfirmed (held until the commit)
    Action->>DB: Commit
    Queue-->>Queue: Release the job after the commit
    Action-->>Controller: Booking
    Controller-->>Browser: Redirect to /events/{event}, toast with the reference
```

## The event cannot be booked

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Controller as BookingController
    participant Action as ReserveSeats
    participant Event
    participant DB as PostgreSQL
    participant Handler as Exception handler

    Browser->>Middleware: POST /events/{event}/bookings
    Middleware->>Middleware: Check login, rate limit, policy and quantity
    Middleware->>Controller: store(request, event)
    Controller->>Action: handle(event, user, quantity)
    Action->>DB: Begin, lock the event row
    DB-->>Action: Event row
    Action->>Event: reserve(user, quantity)
    Event-->>Action: EventNotBookable
    Action->>DB: Roll back
    Action-->>Handler: EventNotBookable
    Handler-->>Browser: Redirect back, error toast
```

## The user already has a booking

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Controller as BookingController
    participant Action as ReserveSeats
    participant Event
    participant DB as PostgreSQL
    participant Handler as Exception handler

    Browser->>Middleware: POST /events/{event}/bookings
    Middleware->>Middleware: Check login, rate limit, policy and quantity
    Middleware->>Controller: store(request, event)
    Controller->>Action: handle(event, user, quantity)
    Action->>DB: Begin, lock the event row
    DB-->>Action: Event row
    Action->>Event: reserve(user, quantity)
    Event->>DB: Read the confirmed booking of the user
    DB-->>Event: Confirmed booking
    Event-->>Action: AlreadyBooked
    Action->>DB: Roll back
    Action-->>Handler: AlreadyBooked
    Handler-->>Browser: Redirect back, error toast
```

## Not enough seats

```mermaid
sequenceDiagram
    participant Browser
    participant Middleware
    participant Controller as BookingController
    participant Action as ReserveSeats
    participant Event
    participant DB as PostgreSQL
    participant Handler as Exception handler

    Browser->>Middleware: POST /events/{event}/bookings
    Middleware->>Middleware: Check login, rate limit, policy and quantity
    Middleware->>Controller: store(request, event)
    Controller->>Action: handle(event, user, quantity)
    Action->>DB: Begin, lock the event row
    DB-->>Action: Event row
    Action->>Event: reserve(user, quantity)
    Event->>DB: Read the confirmed booking of the user
    DB-->>Event: No booking
    Event-->>Action: NotEnoughSeats
    Action->>DB: Roll back
    Action-->>Handler: NotEnoughSeats
    Handler-->>Browser: Redirect back, error under "Seats" and error toast
```
