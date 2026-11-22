export type HistoryData = {
lines: HistoryLineData[],
next: string | null,
};
export type HistoryLineData = {
at: string,
reason: string,
before: number,
after: number,
change: number,
note: string | null,
who: string,
staffName: string | null,
staffHidden: boolean,
orderId: string | null,
};
export type HoldsData = {
orders: number,
pieces: number,
bought: number,
gift: number,
};
export type InventoryFrameData = {
storeCode: string,
storeName: string,
stores: InventoryStoreOptionData[],
off: boolean,
wired: boolean,
mayViewProducts: boolean,
defaultThreshold: number,
reasons: InventoryReasonsData,
};
export type InventoryReasonsData = {
wired: string,
archived: string,
};
export type InventoryStoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
export type LowStockPage = {
storeFrame: InventoryFrameData,
storeTimezone: string,
rows: StockRowData[],
next: string | null,
total: number,
};
export type SizeChoicesData = {
rows: StockSizeData[],
next: string | null,
total: number,
alreadyListed: StockSizeData[],
};
export type SizeStockData = {
size: StockSizeData,
figures: StockFiguresData,
lastPieces: boolean | null,
holds: HoldsData,
};
export type StockFiguresData = {
inStock: number,
held: number,
available: number,
returnedExtra: number,
threshold: number,
ownThreshold: number | null,
badge: string | null,
shortBy: number,
countsOnStock: boolean,
stockDependent: boolean,
providerChangedAt: string | null,
holdingOrders: number,
};
export type StockPage = {
storeFrame: InventoryFrameData,
storeTimezone: string,
search: string,
filter: string,
rows: StockRowData[],
next: string | null,
total: number,
};
export type StockRowData = {
size: StockSizeData,
figures: StockFiguresData,
lastPieces: boolean | null,
};
export type StockSizeData = {
variantId: string,
productId: string,
productNameAr: string,
productNameEn: string | null,
photo: string | null,
code: string,
values: string[],
archived: boolean,
switchedOn: boolean,
};
