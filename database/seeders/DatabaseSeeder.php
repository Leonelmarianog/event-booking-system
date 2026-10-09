<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * The demo data of `make fresh`: named accounts, generated organizers and attendees,
 * and events in every state with bookings. The bookings go through the model methods,
 * so the available seats match the bookings and no email is sent. All times are
 * relative to now. The first upcoming event starts in 3 days, so no reminder is due.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The seats of each generated booking, in turn.
     */
    private const QUANTITIES = [1, 2, 1, 1, 2, 1, 3, 1];

    private User $organizer;

    private User $attendee;

    /** @var Collection<int, User> */
    private Collection $guestOrganizers;

    /** @var Collection<int, User> */
    private Collection $crowd;

    /**
     * The first generated attendee of the next generated bookings.
     */
    private int $crowdOffset = 0;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->organizer = User::factory()->create(['name' => 'Olivia Bennett', 'email' => 'organizer@example.com']);
        $this->attendee = User::factory()->create(['name' => 'Alex Carter', 'email' => 'attendee@example.com']);
        User::factory()->admin()->create(['name' => 'Sam Rivera', 'email' => 'admin@example.com']);
        $this->guestOrganizers = User::factory(3)->create();
        $this->crowd = User::factory(180)->create();

        $this->seedUpcomingEvents();
        $this->seedPastEvent();
        $this->seedCancelledEvent();
        $this->seedDrafts();
    }

    /**
     * The 13 upcoming published events. "Intro to Pottery" is sold out, and the
     * attendee account books three events and cancels one booking.
     */
    private function seedUpcomingEvents(): void
    {
        [$marta, $james, $priya] = $this->guestOrganizers->all();

        $meetup = $this->publishedEvent($this->organizer, 'Laravel Meetup Lisbon', 'Impact Hub Lisbon', 'Talks on queues, testing and deployment, then pizza and drinks.', 3, '18:30', 60);
        $this->bookCrowd($meetup, 12);
        $this->book($meetup, $this->attendee, 2);

        $pottery = $this->publishedEvent($priya, 'Intro to Pottery', 'Clay Studio, Porto', 'A hands-on evening class for beginners. All materials are included.', 5, '19:00', 8);
        foreach ($this->crowd->slice(20, 4) as $user) {
            $this->book($pottery, $user, 2);
        }

        $trailRun = $this->publishedEvent($marta, 'Sunday Trail Run', 'Monsanto Park, Lisbon', 'A relaxed 10 km trail run with a coffee stop at the end.', 6, '09:00', 40);
        $this->bookCrowd($trailRun, 9);
        $this->book($trailRun, $this->attendee, 1);

        $this->bookCrowd($this->publishedEvent($this->organizer, 'Product Design Workshop', 'LX Factory, Lisbon', 'Learn to sketch, test and improve a product idea in one afternoon.', 8, '14:00', 25), 6);
        $this->bookCrowd($this->publishedEvent($james, 'Jazz Night at the Harbour', 'Armazém 16, Lisbon', 'A local quartet plays standards and new pieces in a riverside warehouse. Doors open at 20:30.', 10, '21:00', 300), 175);

        $photoWalk = $this->publishedEvent($marta, 'Photography Walk: Old Town', 'Alfama, Lisbon', 'A guided walk for photographers of all levels. Bring any camera.', 12, '10:30', 15);
        $this->bookCrowd($photoWalk, 5);
        $this->book($photoWalk, $this->attendee, 1);

        $this->bookCrowd($this->publishedEvent($this->organizer, 'Startup Pitch Evening', 'Startup Lisboa', 'Ten early-stage teams pitch to a panel of investors and the audience.', 14, '18:00', 80), 10);

        $boardGames = $this->publishedEvent($priya, 'Board Game Social', 'Café Gato, Porto', 'Meet new people over classic and modern board games.', 16, '19:30', 30);
        $this->bookCrowd($boardGames, 7);
        $this->cancelBooking($boardGames, $this->book($boardGames, $this->attendee, 1));

        $this->bookCrowd($this->publishedEvent($marta, 'Beginner Spanish Conversation', 'City Library, Coimbra', 'Practice everyday Spanish in small groups with a native speaker.', 18, '17:00', 20), 4);
        $this->bookCrowd($this->publishedEvent($this->organizer, 'Open Source Contribution Day', 'Faculty of Sciences, University of Lisbon', 'Pick an issue, pair with a maintainer and open your first pull request.', 21, '10:00', 50), 8);
        $this->bookCrowd($this->publishedEvent($james, 'Wine Tasting: Douro Valley', 'Vinho Wine Bar, Porto', 'Taste six wines from the Douro with a local sommelier.', 25, '20:00', 24), 3);
        $this->bookCrowd($this->publishedEvent($james, 'Yoga in the Park', 'Jardim da Estrela, Lisbon', 'An outdoor class for all levels. Bring a mat and water.', 30, '08:30', 35), 2);
        $this->publishedEvent($this->organizer, 'Cloud Infrastructure Talk', 'Online', 'How a small team runs Laravel in production with Docker and CI.', 45, '16:00', 200);
    }

    /**
     * An event that took place 20 days ago. It is booked while it is upcoming, then
     * moved to the past, as time would do.
     */
    private function seedPastEvent(): void
    {
        $event = $this->publishedEvent($this->organizer, 'JavaScript Meetup Lisbon', 'Impact Hub Lisbon', 'Lightning talks on TypeScript, React and testing.', 1, '18:30', 40);
        $this->bookCrowd($event, 11);
        $this->book($event, $this->attendee, 1);

        $event->starts_at = now()->subDays(20)->setTimeFromTimeString('18:30');
        $event->published_at = now()->subDays(45);
        $event->reminder_sent_at = $event->starts_at->subDay();
        $event->save();
    }

    /**
     * A cancelled event. Its bookings are cancelled with it, as the CancelEvent Action
     * does (BR-E13), but without the emails.
     */
    private function seedCancelledEvent(): void
    {
        $event = $this->publishedEvent($this->organizer, 'Sunset Kayak Tour', 'Belém Docks, Lisbon', 'A guided kayak tour along the river at sunset. Cancelled because of the weather forecast.', 9, '19:00', 12);
        $this->bookCrowd($event, 5);
        $this->book($event, $this->attendee, 2);

        $event->cancel();
        $event->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->get()
            ->each(fn (Booking $booking) => $this->cancelBooking($event, $booking));
        $event->save();
    }

    /**
     * Two drafts of the organizer account.
     */
    private function seedDrafts(): void
    {
        Event::factory()->for($this->organizer, 'organizer')->create([
            'title' => 'Data Visualisation Workshop',
            'venue' => 'LX Factory, Lisbon',
            'description' => 'Turn a spreadsheet into clear charts. Details to follow.',
            'starts_at' => now()->addDays(35)->setTimeFromTimeString('14:00'),
            'capacity' => 30,
        ]);

        Event::factory()->for($this->organizer, 'organizer')->create([
            'title' => 'Street Food Market',
            'venue' => 'Ribeira, Porto',
            'description' => 'Local cooks, live music and long tables. The program is not final yet.',
            'starts_at' => now()->addDays(40)->setTimeFromTimeString('12:00'),
            'capacity' => 300,
        ]);
    }

    /**
     * Create a published event of the organizer that starts the given number of days
     * from now, at the given time.
     */
    private function publishedEvent(User $organizer, string $title, string $venue, string $description, int $days, string $time, int $capacity): Event
    {
        return Event::factory()->published()->for($organizer, 'organizer')->create([
            'title' => $title,
            'venue' => $venue,
            'description' => $description,
            'starts_at' => now()->addDays($days)->setTimeFromTimeString($time),
            'capacity' => $capacity,
        ]);
    }

    /**
     * Book seats of the event for the given number of generated attendees. Each call
     * starts 7 attendees further on, so the events have different people.
     */
    private function bookCrowd(Event $event, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $user = $this->crowd[($this->crowdOffset + $index) % $this->crowd->count()];

            $this->book($event, $user, self::QUANTITIES[$index % count(self::QUANTITIES)]);
        }

        $this->crowdOffset += 7;
    }

    /**
     * Book seats of the event for the user and save both.
     */
    private function book(Event $event, User $user, int $quantity): Booking
    {
        $booking = $event->reserve($user, $quantity);
        $event->save();
        $booking->save();

        return $booking;
    }

    /**
     * Cancel the booking, give its seats back to the event and save both.
     */
    private function cancelBooking(Event $event, Booking $booking): void
    {
        $booking->setRelation('event', $event);
        $booking->cancel();
        $booking->save();
        $event->save();
    }
}
