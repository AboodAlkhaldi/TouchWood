import type { MoneyData } from '../../../../Platform/Public/Dto';
export type BandData = {
fromQuantity: number,
price: MoneyData,
};
export type CategoryChoiceData = {
id: string,
path: string[],
};
export type CategoryDiscountPage = {
storeFrame: PricingFrameData,
storeTimezone: string,
discount: DiscountRowData,
preview: DiscountPreviewData,
};
export type DiscountPreviewData = {
reached: PreviewProductData[],
next: string | null,
reachedProducts: number,
reachedSizes: number,
skippedSizes: number,
unpricedSizes: number,
deepestBasisPoints: number | null,
leftOut: PreviewProductData[],
overlappingSales: OverlapOfferData[],
overlappingDiscounts: OverlapOfferData[],
};
export type DiscountRowData = {
id: string,
categoryId: string,
categoryPath: string[],
percentBasisPoints: number | null,
amountOff: MoneyData | null,
lines: string,
startsAt: string,
endsAt: string | null,
alwaysWins: boolean,
state: string,
leftOut: string[],
leftOutNames: string[],
};
export type DiscountsPage = {
storeFrame: PricingFrameData,
storeTimezone: string,
state: string,
sort: string,
discounts: DiscountRowData[],
next: string | null,
total: number,
};
export type NeedsAPricePage = {
storeFrame: PricingFrameData,
storeTimezone: string,
search: string,
rows: SizeData[],
next: string | null,
total: number,
};
export type OffersPage = {
storeFrame: PricingFrameData,
storeTimezone: string,
state: string,
sort: string,
product: PickedProductData | null,
saleId: string | null,
sales: SaleRowData[],
next: string | null,
total: number,
};
export type OverlapNoteData = {
sales: OverlapOfferData[],
discounts: OverlapOfferData[],
sizes: OverlapSizeData[],
atStart: boolean,
ceilingPercent: number | null,
};
export type OverlapOfferData = {
id: string,
description: string,
startsAt: string,
endsAt: string | null,
alwaysWins: boolean,
};
export type OverlapSizeData = {
variantId: string,
name: string,
retail: MoneyData | null,
newPrice: MoneyData | null,
wins: boolean,
notWinning: string | null,
depthBasisPoints: number | null,
overCeiling: boolean,
};
export type PickedProductData = {
productId: string,
nameAr: string,
nameEn: string | null,
photo: string | null,
codes: string[],
};
export type PreviewProductData = {
productId: string,
nameAr: string,
nameEn: string | null,
sizes: PreviewSizeData[],
};
export type PreviewSizeData = {
variantId: string,
code: string,
name: string,
retail: MoneyData,
newPrice: MoneyData | null,
difference: MoneyData | null,
skipped: string | null,
};
export type PriceRowData = {
size: SizeData,
retail: RetailPriceData | null,
today: TodayPriceData | null,
wholesaleOnly: boolean,
bands: number,
keptNotSoldWholesale: boolean,
firstBand: BandData | null,
soldWholesale: boolean,
};
export type PricesPage = {
storeFrame: PricingFrameData,
storeTimezone: string,
search: string,
filter: string,
rows: PriceRowData[],
next: string | null,
total: number,
};
export type PricingFrameData = {
storeCode: string,
storeName: string,
stores: PricingStoreOptionData[],
off: boolean,
wired: boolean,
ceilingPercent: number,
maySetCeiling: boolean,
mayEditPrices: boolean,
mayEditWholesale: boolean,
mayManageDiscounts: boolean,
mayViewProducts: boolean,
decimals: number,
currency: string,
reasons: PricingReasonsData,
};
export type PricingReasonsData = {
noPrice: string,
notSoldWholesale: string,
archived: string,
wiredPrice: string,
};
export type PricingStoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
export type ProductResultsData = {
rows: PickedProductData[],
next: string | null,
total: number,
};
export type ReachingDiscountData = {
id: string,
categoryId: string,
categoryPath: string[],
state: string,
percentBasisPoints: number | null,
amountOff: MoneyData | null,
lines: string,
priceOnSize: MoneyData | null,
skipped: string | null,
startsAt: string,
endsAt: string | null,
alwaysWins: boolean,
winsNow: boolean,
};
export type ReachingSaleData = {
id: string,
state: string,
percentBasisPoints: number | null,
salePrice: MoneyData | null,
priceOnSize: MoneyData | null,
startsAt: string,
endsAt: string | null,
alwaysWins: boolean,
winsNow: boolean,
};
export type RetailPriceData = {
price: MoneyData,
source: string,
updatedAt: string,
};
export type SaleRowData = {
id: string,
productId: string,
productNameAr: string,
productNameEn: string | null,
state: string,
percentBasisPoints: number | null,
salePrice: MoneyData | null,
startsAt: string,
endsAt: string | null,
createdAt: string | null,
alwaysWins: boolean,
variantIds: string[],
sizeNames: string[],
allSizes: boolean,
sizesOn: number,
productSizesOn: number,
archivedSince: number,
unpricedSizeNames: string[],
};
export type SaleSizeChoiceData = {
variantId: string,
name: string,
code: string,
retail: MoneyData | null,
};
export type SaleSizeData = {
variantId: string,
name: string,
code: string,
archived: boolean,
switchedOn: boolean,
retail: MoneyData | null,
salePrice: MoneyData | null,
};
export type SaleViewData = {
sale: SaleRowData,
sizes: SaleSizeData[],
};
export type ShopBandData = {
minQuantity: number,
unit: MoneyData,
};
export type ShopWholesaleData = {
sizes: Record<string, ShopWholesaleSizeData>,
};
export type ShopWholesaleSizeData = {
price: MoneyData,
bands: ShopBandData[],
};
export type SizeData = {
variantId: string,
productId: string,
productNameAr: string,
productNameEn: string | null,
photo: string | null,
code: string,
values: string[],
archived: boolean,
switchedOn: boolean,
};
export type SizePricesData = {
size: SizeData,
retail: RetailPriceData | null,
todayRetail: TodayPriceData | null,
todayWholesale: TodayPriceData | null,
wholesaleQuantity: number | null,
soldRetail: boolean,
soldWholesale: boolean,
sales: ReachingSaleData[],
discounts: ReachingDiscountData[],
bands: BandData[],
wholesaleMinimum: number | null,
wholesaleMaximum: number | null,
minimumChanged: boolean,
};
export type SizeResultsData = {
rows: SizeData[],
next: string | null,
total: number,
};
export type TodayPriceData = {
price: MoneyData,
before: MoneyData | null,
why: string,
alwaysWins: boolean,
sourceId: string | null,
endsAt: string | null,
};
export type WholesalePage = {
storeFrame: PricingFrameData,
storeTimezone: string,
search: string,
rows: WholesaleRowData[],
next: string | null,
total: number,
};
export type WholesaleRowData = {
size: SizeData,
retail: RetailPriceData | null,
bands: BandData[],
minimum: number | null,
maximum: number | null,
keptNotSoldWholesale: boolean,
noRetailPrice: boolean,
minimumChanged: boolean,
};
