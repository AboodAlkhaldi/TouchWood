export type CarrierCountryData = {
code: string,
name: string,
own: boolean,
carriers: CarrierData[],
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
};
export type CarrierPage = {
carrier: CarrierData,
countryName: string,
mayManage: boolean,
storeCode: string,
storeName: string,
storeIsActive: boolean,
};
export type CarriersPage = {
countries: CarrierCountryData[],
mayManage: boolean,
storeCode: string,
storeName: string,
storeIsActive: boolean,
stores: StoreOptionData[],
};
export type StoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
