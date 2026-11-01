export type GatewayKeyData = {
last6: string,
expiresOn: string,
daysLeft: number,
rejected: boolean,
setAt: string,
};
export type GatewayPage = {
storeCode: string,
storeName: string,
storeTimezone: string,
stores: GatewayStoreData[],
offered: boolean,
setUp: boolean,
active: boolean,
mode: string | null,
liveKey: GatewayKeyData | null,
testKey: GatewayKeyData | null,
noticeLive: string,
noticeTest: string,
currentDomain: string,
applePayLive: boolean,
applePayTest: boolean,
windowMinutes: number,
waitMinutes: number,
holdMinutes: number,
};
export type GatewayStoreData = {
code: string,
name: string,
isActive: boolean,
};
