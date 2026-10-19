export type ConnectionData = {
storeId: string,
providers: string[],
provider: string | null,
providerName: string | null,
providerReady: boolean,
address: string | null,
settings: Record<string, string>,
fields: string[],
keySet: boolean,
changedAt: string | null,
state: string,
failure: FailureData | null,
lastReadAt: string | null,
lastFullReadAt: string | null,
noticeAddress: string,
noticeMostCodes: number,
stopsWhenUnwired: number,
};
export type FailureData = {
code: string | null,
text: string | null,
providerSaid: string | null,
};
export type KindCountData = {
kind: string,
count: number,
};
export type OfferRefData = {
id: string,
exists: boolean,
description: string | null,
};
export type ProblemsData = {
entries: ReportEntryData[],
next: string | null,
total: number,
allOpen: number,
kinds: KindCountData[],
};
export type ProviderPage = {
storeFrame: SyncFrameData,
storeTimezone: string,
connection: ConnectionData,
};
export type ReadLineData = {
variantId: string,
code: string | null,
sizeExists: boolean,
productId: string | null,
productNameAr: string | null,
productNameEn: string | null,
values: string[],
before: string | null,
after: string,
};
export type ReadMomentData = {
kind: string,
at: string,
};
export type ReadRowData = {
id: string,
kind: string,
state: string,
askedBy: string,
askedBySystem: boolean,
codes: number | null,
pricesChanged: number,
stockChanged: number,
skipped: number,
failure: FailureData | null,
queuedAt: string,
startedAt: string | null,
finishedAt: string | null,
tookSeconds: number | null,
};
export type ReadsData = {
reads: ReadRowData[],
next: string | null,
total: number,
};
export type ReportEntryData = {
id: number,
about: ReportedData,
firstSeenAt: string,
lastSeenAt: string,
};
export type ReportedData = {
kind: string,
code: string | null,
variantId: string | null,
sizeExists: boolean,
productId: string | null,
productNameAr: string | null,
productNameEn: string | null,
values: string[],
offer: OfferRefData | null,
discount: OfferRefData | null,
readId: string | null,
failure: FailureData | null,
detail: Record<string, any>,
};
export type SectionTotalsData = {
prices: number,
stock: number,
reported: number,
codes: number,
};
export type SyncFrameData = {
storeId: string,
storeCode: string,
storeName: string,
stores: SyncStoreOptionData[],
off: boolean,
wired: boolean,
mayView: boolean,
mayRun: boolean,
mayWire: boolean,
mayViewProducts: boolean,
mayViewOffers: boolean,
nowWaiting: boolean,
syncNowRefusal: string | null,
reasons: SyncReasonsData,
};
export type SyncReadPage = {
storeCode: string,
storeTimezone: string,
read: ReadRowData,
sections: SectionTotalsData,
section: string,
page: number,
perPage: number,
more: boolean,
lines: ReadLineData[],
reported: ReportedData[],
codes: string[],
mayViewProducts: boolean,
mayViewOffers: boolean,
};
export type SyncReasonsData = {
sizeGone: string,
readFailedStays: string,
};
export type SyncReportPage = {
storeFrame: SyncFrameData,
storeTimezone: string,
status: SyncStatusData,
tab: string,
kind: string | null,
problems: ProblemsData | null,
reads: ReadsData | null,
};
export type SyncStatusData = {
provider: string | null,
providerName: string | null,
state: string,
failure: FailureData | null,
lastReadAt: string | null,
lastFullReadAt: string | null,
running: ReadMomentData | null,
waiting: ReadMomentData | null,
};
export type SyncStoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
