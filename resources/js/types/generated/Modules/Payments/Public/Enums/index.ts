export type OnlinePaymentReason = 'NO_GATEWAY' | 'GATEWAY_OFF' | 'KEY_REJECTED' | 'COMPANY';
export type PaymentAudience = 'INDIVIDUAL' | 'COMPANY';
export type PaymentMode = 'TEST' | 'LIVE';
export type PaymentOwnerKind = 'TRY' | 'REQUEST';
export type PaymentState = 'NONE' | 'OPEN' | 'IN_PROGRESS' | 'UNKNOWN' | 'SUCCEEDED' | 'NOT_ACCEPTED' | 'FAILED' | 'EXPIRED';
