export type EventStatus = 'draft' | 'published' | 'cancelled';

export type EventDetails = {
    id: number;
    title: string;
    description: string;
    venue: string;
    starts_at: string;
    capacity: number;
    seats_available: number;
    status: EventStatus;
    organizer_name: string;
};

export type OrganizerEventRow = {
    id: number;
    title: string;
    starts_at: string;
    status: EventStatus;
    capacity: number;
    seats_booked: number;
    can_update: boolean;
};

export type UpcomingEvent = {
    id: number;
    title: string;
    starts_at: string;
    venue: string;
    seats_available: number;
};

export type EventBookingBox =
    | {
          state: 'booked';
          reference: string;
          quantity: number;
          can_cancel: boolean;
      }
    | { state: 'available'; max_quantity: number }
    | { state: 'login' }
    | { state: 'sold_out' };
