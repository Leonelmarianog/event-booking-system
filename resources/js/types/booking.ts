export type BookingStatus = 'confirmed' | 'cancelled';

export type BookingRow = {
    reference: string;
    quantity: number;
    status: BookingStatus;
    can_cancel: boolean;
    event_cancelled: boolean;
    event: {
        id: number;
        title: string;
        venue: string;
        starts_at: string;
    };
};

export type AttendeeRow = {
    reference: string;
    name: string;
    email: string;
    quantity: number;
    booked_at: string;
};
