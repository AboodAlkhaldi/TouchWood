export type AssignmentData = {
customer: CustomerNameData,
assignedAt: string,
segment: string | null,
uses: number,
};
export type ChoiceData = {
id: string,
name: string,
detail: string | null,
};
export type CouponFormData = {
id: string,
code: string,
nameAr: string,
nameEn: string,
eligibility: string,
discountKind: string,
percent: number | null,
cap: string | null,
amount: string | null,
minimum: string | null,
startsAt: string,
endsAt: string | null,
maxUsesPerCustomer: number | null,
maxUsesTotal: number | null,
allowWithPoints: boolean,
onReducedLines: boolean,
forRetail: boolean,
forWholesale: boolean,
forPublic: boolean,
forCompany: boolean,
rules: RuleData[],
active: boolean,
state: string,
usesTotal: number,
assignedCount: number,
};
export type CouponPage = {
coupon: CouponFormData | null,
tab: string,
uses: CouponUseData[],
usesNextAt: string | null,
usesNextId: number | null,
customers: AssignmentData[],
customersNextAt: string | null,
customersNextId: string | null,
categories: ChoiceData[],
brands: ChoiceData[],
products: ChoiceData[],
segments: SegmentChoiceData[],
ceilingPercent: number,
currency: string,
exponent: number,
mayManage: boolean,
storeCode: string,
storeName: string,
storeIsActive: boolean,
storeTimezone: string,
};
export type CouponRowData = {
id: string,
code: string,
name: string,
eligibility: string,
discountKind: string,
percent: number | null,
amount: MoneyData | null,
cap: MoneyData | null,
startsAt: string,
endsAt: string | null,
usesTotal: number,
maxUsesTotal: number | null,
state: string,
};
export type CouponUseData = {
id: number,
orderNumber: string,
customer: CustomerNameData,
discount: MoneyData,
usedAt: string,
};
export type CouponsListPage = {
coupons: CouponRowData[],
nextAt: string | null,
nextId: string | null,
state: string | null,
code: string | null,
mayManage: boolean,
storeCode: string,
storeName: string,
storeIsActive: boolean,
storeTimezone: string,
stores: StoreOptionData[],
};
export type CustomerNameData = {
id: string,
name: string | null,
deleted: boolean,
};
export type MoneyData = {
amount: string,
currency: string,
};
export type RuleData = {
kind: string,
target: string,
id: string,
name: string | null,
path: string | null,
sold: boolean,
};
export type SegmentChoiceData = {
id: string,
name: string,
audience: string,
memberCount: number,
};
export type StoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
