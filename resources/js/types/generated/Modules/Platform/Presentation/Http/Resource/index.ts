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
};
export type AuditRow = {
id: string,
occurredAt: string,
action: string,
actionLabel: string,
subjectType: string,
subjectId: string,
source: string,
actorType: string,
actorId: string | null,
actorName: string | null,
requestedByType: string | null,
requestedById: string | null,
storeName: string | null,
ipAddress: string | null,
changes: AuditChangeRow[],
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
storeCount: number,
exponentLocked: boolean,
};
export type MediaFileRow = {
id: string,
filename: string,
mime: string,
bytes: number,
size: string,
width: number | null,
height: number | null,
visibility: string,
variantsStatus: string | null,
retryable: boolean,
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
};
export type SettingGroup = {
module: string,
label: string,
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
};
export type StoresPage = {
stores: StoreRow[],
timezones: string[],
};
