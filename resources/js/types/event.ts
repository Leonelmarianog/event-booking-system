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
