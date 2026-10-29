export type AddableCountry = {
code: string,
name: string,
otherName: string,
ours: boolean,
};
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
export type FreeCurrencyRow = {
code: string,
name: string,
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
export type NewStoreForm = {
freeCurrencies: FreeCurrencyRow[],
countries: StoreCountryOption[],
zones: Record<string, string>,
exponents: number[],
nextPosition: number,
};
export type PlaceCountryRow = {
code: string,
name: string,
otherName: string,
retired: boolean,
hasPlaces: boolean,
store: PlaceStoreRow | null,
regions: number,
cities: number,
};
export type PlaceCrumb = {
id: string,
kind: string,
name: string,
retired: boolean,
};
export type PlaceLevelPage = {
country: PlaceCountryRow,
parents: PlaceCrumb[],
kind: string,
places: PlaceRow[],
hiddenBy: string | null,
};
export type PlaceRow = {
id: string,
kind: string,
name: string,
otherName: string,
nameAr: string,
nameEn: string,
retired: boolean,
cities: number,
districts: number,
};
export type PlaceStoreRow = {
code: string,
name: string,
active: boolean,
};
export type PlacesPage = {
countries: PlaceCountryRow[],
addable: AddableCountry[],
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
storeCode: string | null,
stores: StoreOption[],
storeTimezone: string | null,
};
export type ShipToChoice = {
code: string,
name: string,
hasPlaces: boolean,
store: PlaceStoreRow | null,
};
export type ShipToRow = {
code: string,
name: string,
own: boolean,
};
export type StoreChoiceRow = {
code: string,
name: string,
countryCode: string,
currency: string,
symbol: string,
href: string,
};
export type StoreCountryOption = {
code: string,
name: string,
ours: boolean,
};
export type StoreHomePage = {
name: string,
currency: string,
symbol: string,
};
export type StoreOption = {
code: string,
name: string,
isActive: boolean,
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
shipsTo: ShipToRow[],
};
export type StoresPage = {
stores: StoreRow[],
timezones: string[],
maySwitch: boolean,
add: NewStoreForm | null,
shipToChoices: ShipToChoice[] | null,
};
