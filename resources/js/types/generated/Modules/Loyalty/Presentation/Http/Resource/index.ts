export type CustomerNameData = {
id: string,
name: string | null,
email: string | null,
deleted: boolean,
};
export type CustomerPointsPage = {
customer: CustomerNameData,
points: number,
worth: MoneyData,
expiring: ExpiringData | null,
lots: LotData[],
history: HistoryRowData[],
nextAt: string | null,
nextId: number | null,
mayChangePoints: boolean,
mayOpenCustomer: boolean,
mostByHand: number,
storeCode: string,
storeName: string,
storeIsActive: boolean,
storeTimezone: string,
programmeOn: boolean,
stores: StoreOptionData[],
};
export type ExpiringData = {
points: number,
day: string,
};
export type HistoryRowData = {
id: number,
kind: string,
points: number,
occurredAt: string,
orderNumber: string | null,
staffName: string | null,
reason: string | null,
};
export type LotData = {
points: number,
expiresAt: string,
};
export type MoneyData = {
amount: string,
currency: string,
};
export type MyPointsPage = {
points: number,
worth: MoneyData,
expiring: ExpiringData | null,
lots: LotData[],
history: HistoryRowData[],
nextAt: string | null,
nextId: number | null,
programmeOn: boolean,
earnPerUnit: number,
pointsPerUnitOff: number,
unit: MoneyData,
timezone: string,
};
export type PointHolderData = {
accountId: string,
customer: CustomerNameData,
points: number,
worth: MoneyData,
expiring: ExpiringData | null,
lastAt: string | null,
};
export type PointHoldersPage = {
holders: PointHolderData[],
nextPoints: number | null,
nextAccountId: string | null,
tooMany: boolean,
search: string | null,
storeCode: string,
storeName: string,
storeIsActive: boolean,
storeTimezone: string,
programmeOn: boolean,
stores: StoreOptionData[],
};
export type PointsSectionData = {
customerId: string,
stores: StorePointsData[],
giveStoreCode: string | null,
};
export type RedemptionRowData = {
id: number,
orderNumber: string,
customer: CustomerNameData,
points: number,
discount: MoneyData,
occurredAt: string,
};
export type RedemptionsListPage = {
redemptions: RedemptionRowData[],
nextAt: string | null,
nextId: number | null,
storeCode: string,
storeName: string,
storeIsActive: boolean,
storeTimezone: string,
programmeOn: boolean,
stores: StoreOptionData[],
};
export type StoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
export type StorePointsData = {
code: string,
name: string,
isActive: boolean,
points: number,
worth: MoneyData,
expiring: ExpiringData | null,
mayOpen: boolean,
};
