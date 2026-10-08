export type BookingStatus = 'confirmed' | 'cancelled';

export type BookingRow = {
    reference: string;
    quantity: number;
    status: BookingStatus;
    event: {
        id: number;
        title: string;
        venue: string;
        starts_at: string;
    };
};
