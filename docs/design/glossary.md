# Glossary

Each term has one meaning in this design. The code uses the same names.

## People

| Term         | Meaning                                                                                   | In code              |
| ------------ | ----------------------------------------------------------------------------------------- | -------------------- |
| Visitor      | A person who is not logged in.                                                            | No model             |
| User         | A person with an account who is logged in.                                                | `User`               |
| Organizer    | The user who created an event. Each event has one organizer.                              | `Event.organizer_id` |
| Attendee     | A user who holds a booking for an event.                                                  | `Booking.user_id`    |
| Admin        | A user with the admin flag. An admin can moderate events of other users.                  | `User.is_admin`      |
| Deleted user | A user who deleted their account. The system keeps the row without personal data (BR-U4). | `User.anonymized_at` |

A user is not "an organizer" or "an attendee" for the whole system. The role depends on
the event. The same user can organize one event and book seats for a different event.

## Events

| Term            | Meaning                                                                         | In code                   |
| --------------- | ------------------------------------------------------------------------------- | ------------------------- |
| Event           | A thing that happens at one place and one time, with a limited number of seats. | `Event`                   |
| Start time      | The date and time when the event starts. The event has no end time.             | `Event.starts_at`         |
| Started event   | An event with a start time that is now or in the past.                          | `Event::hasStarted()`     |
| Upcoming event  | An event with a start time in the future.                                       | `Event::upcoming()` scope |
| Capacity        | The total number of seats of the event.                                         | `Event.capacity`          |
| Available seats | The number of seats that users can still book.                                  | `Event.seats_available`   |
| Booked seats    | Capacity minus available seats.                                                 | Calculated                |
| Event status    | The state of the event: draft, published or cancelled.                          | `EventStatus` enum        |
| Draft           | The event exists, but only the organizer can see it. Users cannot book it.      | `EventStatus::Draft`      |
| Published       | All users can see the event. Users can book it.                                 | `EventStatus::Published`  |
| Cancelled       | The event will not happen. Users cannot book it.                                | `EventStatus::Cancelled`  |
| Bookable event  | A published event that has not started and has available seats.                 | `Event::isBookable()`     |

## Bookings

| Term              | Meaning                                                            | In code                    |
| ----------------- | ------------------------------------------------------------------ | -------------------------- |
| Booking           | A reservation of one or more seats for one event, by one user.     | `Booking`                  |
| Quantity          | The number of seats in one booking.                                | `Booking.quantity`         |
| Booking reference | A unique code that identifies a booking. The user sees this code.  | `Booking.reference` (ULID) |
| Booking status    | The state of the booking: confirmed or cancelled.                  | `BookingStatus` enum       |
| Confirmed booking | An active booking. Its seats are not available to other users.     | `BookingStatus::Confirmed` |
| Cancelled booking | A booking that is no longer active. Its seats are available again. | `BookingStatus::Cancelled` |

## System

| Term             | Meaning                                                                                                                                                                              | In code                                   |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------- |
| Action           | One class that does one use case, a read or a write. A write Action controls the transaction, the locks and the notifications. Each Action has its own directory with a `README.md`. | `app/Actions/<Name>/`                     |
| Domain exception | An error that a business rule causes. The user sees a clear message.                                                                                                                 | `app/Exceptions/Domain`                   |
| Notification     | An email that the system sends to a user. A queue worker sends it.                                                                                                                   | `app/Notifications`                       |
| Reminder         | A notification that the system sends to attendees before the event starts. The system sends it one time for each event.                                                              | `EventReminder`, `Event.reminder_sent_at` |
