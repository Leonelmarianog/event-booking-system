export type BookingStatus = 'confirmed' | 'cancelled';

export type BookingRow = {
    reference: string;
    quantity: number;
    status: BookingStatus;
    can_cancel: boolean;
    event: {
        id: number;
        title: string;
        venue: string;
        starts_at: string;
    };
};
