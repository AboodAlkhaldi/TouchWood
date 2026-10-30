import type { MoneyData } from '../../../../Platform/Public/Dto';
export type CarrierCountryData = {
code: string,
name: string,
own: boolean,
carriers: CarrierData[],
uncovered: PricePlaceData[],
};
export type CarrierData = {
id: string,
countryCode: string,
nameAr: string,
nameEn: string,
logoMediaId: string | null,
logo: string | null,
phone: string | null,
website: string | null,
trackingPattern: string | null,
active: boolean,
freeAbove: MoneyData | null,
};
export type CarrierPage = {
carrier: CarrierData,
countryName: string,
mayManage: boolean,
storeCode: string,
storeName: string,
storeIsActive: boolean,
prices: PriceGroupData[],
pricesVersion: string,
storeFreeAbove: MoneyData | null,
mayManagePrices: boolean,
currency: string,
decimals: number,
};
export type CarriersPage = {
countries: CarrierCountryData[],
mayManage: boolean,
storeCode: string,
storeName: string,
storeIsActive: boolean,
stores: StoreOptionData[],
storeFreeAbove: MoneyData | null,
mayManagePrices: boolean,
currency: string,
decimals: number,
};
export type PickPlaceData = {
id: string,
name: string,
otherName: string,
};
export type PriceGroupData = {
id: string,
amount: MoneyData,
places: PricePlaceData[],
};
export type PricePlaceData = {
id: string,
kind: string,
name: string,
under: string | null,
retired: boolean,
};
export type StoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
