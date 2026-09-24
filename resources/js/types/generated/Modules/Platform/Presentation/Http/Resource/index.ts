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
