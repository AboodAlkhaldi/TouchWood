import type { MoneyData } from '../../../../Platform/Public/Dto';
export type BoxData = {
id: string,
name: string,
lengthMm: number,
widthMm: number,
heightMm: number,
maxWeightG: number,
active: boolean,
};
export type BoxesPage = {
paddingMm: number,
boxes: BoxData[],
handPacked: PackingSizesData,
filter: string | null,
storeCode: string,
storeName: string,
storeIsActive: boolean,
stores: StoreOptionData[],
};
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
export type PackingSizeData = {
variantId: string,
productName: string,
code: string,
values: string,
photo: string | null,
archived: boolean,
};
export type PackingSizesData = {
rows: PackingSizeData[],
next: string | null,
total: number,
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
