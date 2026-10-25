export type CancelReason = 'CHANGED_MIND' | 'ORDERED_BY_MISTAKE' | 'FOUND_CHEAPER' | 'TOO_SLOW' | 'OTHER' | 'BY_STAFF';
export type CustomerOrderStatus = 'NEW' | 'PROCESSING' | 'SHIPPED' | 'DELIVERED' | 'CANCELLED' | 'REFUNDED';
export type OrderAudience = 'PUBLIC' | 'COMPANY';
export type PaymentStatus = 'NOTHING_DUE' | 'UNPAID' | 'PARTLY_PAID' | 'PAID' | 'REFUND_DUE' | 'REFUNDED';
export type ReturnReason = 'DAMAGED' | 'WRONG_ITEM' | 'NOT_AS_DESCRIBED' | 'CHANGED_MIND' | 'OTHER';
export type WayToPay = 'ONLINE' | 'BANK_TRANSFER' | 'STAFF_CONTACT';
