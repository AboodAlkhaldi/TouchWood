export type Audience = 'PUBLIC' | 'COMPANY';
export type CouponEligibility = 'PUBLIC' | 'ASSIGNED';
export type CouponRefusal = 'UNKNOWN_CODE' | 'INACTIVE' | 'NOT_STARTED' | 'ENDED' | 'USED_UP' | 'USED_UP_FOR_YOU' | 'NOT_ASSIGNED_TO_YOU' | 'BELOW_MINIMUM' | 'NOTHING_ELIGIBLE' | 'WORTH_NOTHING' | 'NOT_WITH_POINTS' | 'PASSES_CEILING';
export type DiscountKind = 'PERCENT' | 'FIXED';
export type SegmentAudience = 'INDIVIDUAL' | 'COMPANY';
export type SegmentRule = 'NO_ORDERS_EVER' | 'NO_ORDER_FOR' | 'AT_LEAST_ORDERS';
export type SegmentType = 'STATIC' | 'DYNAMIC';
