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
