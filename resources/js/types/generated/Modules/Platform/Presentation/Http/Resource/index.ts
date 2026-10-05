export type AuditChangeRow = {
attribute: string,
from: string | null,
to: string | null,
personal: boolean,
};
export type AuditLogPage = {
entries: AuditRow[],
actions: string[],
sources: string[],
filters: Record<string, string | null>,
nextOccurredAt: string | null,
nextId: number | null,
actionLabels: Record<string, string>,
};
export type AuditRow = {
id: string,
occurredAt: string,
action: string,
actionLabel: string,
subjectType: string,
subjectId: string | null,
source: string,
actorType: string,
actorId: string | null,
actorName: string | null,
requestedByType: string | null,
requestedById: string | null,
storeName: string | null,
ipAddress: string | null,
changes: AuditChangeRow[],
withheld: boolean,
requestedByName: string | null,
subjectName: string | null,
};
export type ChooseStorePage = {
stores: StoreChoiceRow[],
};
export type CurrenciesPage = {
currencies: CurrencyRow[],
exponents: number[],
};
export type CurrencyRow = {
code: string,
name: string,
nameAr: string,
nameEn: string,
abbreviationAr: string,
abbreviationEn: string,
sign: string | null,
exponent: number,
stores: CurrencyStoreRow[],
exponentLocked: boolean,
deletable: boolean,
};
export type CurrencyStoreRow = {
name: string,
isActive: boolean,
};
export type FailedJobPage = {
job: FailedJobRowData,
error: string,
};
export type FailedJobRowData = {
id: string,
name: string,
failedAt: string,
triesAllowed: number | null,
queue: string,
errorLine: string,
retryable: boolean,
};
export type FailedJobsPage = {
jobs: FailedJobRowData[],
nextFailedAt: string | null,
nextId: string | null,
};
export type MediaFileRow = {
id: string,
filename: string,
mime: string | null,
bytes: number | null,
width: number | null,
height: number | null,
visibility: string,
variantsStatus: string | null,
retryable: boolean | null,
uploadedAt: string,
altAr: string | null,
altEn: string | null,
thumbnailUrl: string | null,
usedIn: string[],
deleteBlocked: boolean,
};
export type MediaPage = {
media: MediaFileRow[],
nextCreatedAt: string | null,
nextId: string | null,
mayUpload: boolean,
mayUpdate: boolean,
mayDelete: boolean,
mayUploadPrivate: boolean,
};
export type SettingGroup = {
module: string,
label: string,
line: string | null,
settings: SettingRowData[],
};
export type SettingRowData = {
key: string,
label: string,
scope: string,
type: string,
value: any,
default: any,
isDefault: boolean,
sensitive: boolean,
min: number | null,
max: number | null,
};
export type SettingsPage = {
groups: SettingGroup[],
storeName: string | null,
};
export type StoreChoiceRow = {
code: string,
name: string,
countryCode: string,
currency: string,
symbol: string,
href: string,
};
export type StoreHomePage = {
name: string,
currency: string,
symbol: string,
};
export type StoreRow = {
id: string,
code: string,
name: string,
nameAr: string,
nameEn: string,
countryCode: string,
currencyCode: string,
currencySymbol: string,
taxRateBasisPoints: number,
taxRatePercent: string,
timezone: string,
position: number,
editable: boolean,
isActive: boolean,
isBase: boolean,
switchable: boolean,
};
export type StoresPage = {
stores: StoreRow[],
timezones: string[],
maySwitch: boolean,
};
