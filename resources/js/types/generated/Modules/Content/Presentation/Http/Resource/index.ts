export type BannerData = {
id: string,
placement: string,
categoryId: string | null,
name: string,
link: string | null,
hasWords: boolean,
altAr: string,
altEn: string | null,
state: string,
startsAt: string | null,
endsAt: string | null,
images: Record<string, string>,
version: number,
translationUnread: boolean,
};
export type BannerFormPageData = {
store: ContentStoreData,
storeTimezone: string,
placement: string,
banner: BannerData | null,
images: Record<string, ContentImageData>,
categories: CategoryChoiceData[],
translationReady: boolean,
};
export type BannerRowData = {
id: string,
name: string,
categoryId: string | null,
state: string,
startsAt: string | null,
endsAt: string | null,
position: number,
thumbnail: string | null,
};
export type BannersPageData = {
stores: ContentStoreData[],
storeCode: string,
storeTimezone: string,
placement: string,
counts: Record<string, number>,
banners: BannerRowData[],
categories: CategoryChoiceData[],
};
export type BarData = {
textAr: string | null,
textEn: string | null,
link: string | null,
isOn: boolean,
startsAt: string | null,
endsAt: string | null,
version: number,
translationUnread: boolean,
};
export type BarsPageData = {
stores: ContentStoreData[],
storeCode: string,
storeTimezone: string,
tab: string,
bar: BarData,
smartBars: SmartBarData[],
categories: CategoryChoiceData[],
translationReady: boolean,
};
export type BlogCategoriesPageData = {
stores: ContentStoreData[],
storeCode: string,
categories: BlogCategoryData[],
postsCount: number,
translationReady: boolean,
};
export type BlogCategoryData = {
id: string,
nameAr: string,
nameEn: string,
slugAr: string,
slugEn: string,
posts: number,
};
export type CampaignData = {
id: string,
nameAr: string,
nameEn: string | null,
startsLocal: string,
endsLocal: string,
themeId: string | null,
version: number,
state: string,
halfLive: boolean,
translationUnread: boolean,
};
export type CampaignFormPageData = {
stores: ContentStoreData[],
campaign: CampaignData | null,
runs: CampaignRunData[],
linkedTheme: CampaignThemeData | null,
themes: NamedChoiceData[],
mayOpenThemes: boolean,
translationReady: boolean,
};
export type CampaignNoticeData = {
id: string,
titleAr: string | null,
titleEn: string | null,
status: string,
};
export type CampaignOfferData = {
kind: string,
id: string,
startsAt: string,
endsAt: string | null,
alwaysWins: boolean,
sizes: number,
};
export type CampaignPreviewData = {
products: CampaignPreviewProductData[],
next: string | null,
reachedProducts: number,
reachedSizes: number,
skippedSizes: number,
deepestBasisPoints: number | null,
otherOffers: CampaignOfferData[],
currency: string,
};
export type CampaignPreviewProductData = {
productId: string,
nameAr: string,
nameEn: string | null,
sizes: CampaignPreviewSizeData[],
};
export type CampaignPreviewSizeData = {
variantId: string,
code: string,
retail: string | null,
newPrice: string | null,
depthBp: number | null,
skipped: string | null,
};
export type CampaignPricesData = {
lines: string,
targets: CampaignTargetData[],
leftOut: CampaignTargetData[],
};
export type CampaignRowData = {
id: string,
nameAr: string,
nameEn: string | null,
runId: string,
state: string,
overall: string,
startsAt: string | null,
endsAt: string | null,
stores: RunStateData[],
themeAr: string | null,
themeEn: string | null,
notice: CampaignNoticeData | null,
version: number,
};
export type CampaignRunData = {
id: string,
store: ContentStoreData,
state: string,
startsAt: string,
endsAt: string,
pricesOpen: boolean,
mayPrice: boolean,
mayNotices: boolean,
ceilingPercent: number | null,
currency: string,
decimals: number,
prices: CampaignPricesData | null,
skipped: CampaignTargetData[],
notice: CampaignNoticeData | null,
drafts: CampaignNoticeData[],
};
export type CampaignTargetData = {
kind: string,
id: string,
nameAr: string,
nameEn: string | null,
detail: string | null,
archived: boolean,
percentBp: number | null,
amountMinor: number | null,
};
export type CampaignThemeData = {
id: string,
nameAr: string,
nameEn: string | null,
startsLocal: string,
endsLocal: string,
state: string,
parts: ThemePartFillData[],
dates: ThemeDateMatchData[],
};
export type CampaignsPageData = {
stores: ContentStoreData[],
storeCode: string,
storeTimezone: string,
tab: string,
counts: Record<string, number>,
campaigns: CampaignRowData[],
next: string | null,
};
export type CategoryChoiceData = {
id: string,
pathAr: string,
pathEn: string,
};
export type ContentImageData = {
id: string,
name: string,
width: number | null,
height: number | null,
status: string,
thumbnail: string | null,
};
export type ContentStoreData = {
id: string,
code: string,
name: string,
isOn: boolean,
timezone: string,
};
export type DocumentData = {
id: string,
status: string,
publishedAt: string | null,
version: number,
ar: DocumentTextData,
en: DocumentTextData | null,
shareAr: string | null,
shareEn: string | null,
categoryId: string | null,
coverMediaId: string | null,
products: string[],
photos: string[],
translationUnread: boolean,
};
export type DocumentFormPageData = {
store: ContentStoreData,
kind: string,
document: DocumentData | null,
images: Record<string, ContentImageData>,
products: ProductChoiceData[],
categories: BlogCategoryData[],
shopAddress: string,
pathPrefix: string,
translationReady: boolean,
linkedFrom: Record<string, number>,
};
export type DocumentRowData = {
id: string,
status: string,
titleAr: string,
titleEn: string | null,
addressAr: string,
addressEn: string | null,
categoryId: string | null,
publishedAt: string | null,
changedAt: string | null,
changedBy: string | null,
version: number,
};
export type DocumentTextData = {
title: string,
slug: string,
body: string,
images: string[],
seoTitle: string | null,
seoDescription: string | null,
summary: string | null,
coverAlt: string | null,
city: string | null,
};
export type DocumentsPageData = {
stores: ContentStoreData[],
storeCode: string,
storeTimezone: string,
kind: string,
tab: string,
counts: Record<string, number>,
rows: DocumentRowData[],
next: string | null,
categories: BlogCategoryData[],
category: string | null,
postsCount: number,
};
export type FooterData = {
aboutAr: string | null,
aboutEn: string | null,
phone: string | null,
email: string | null,
addressAr: string | null,
addressEn: string | null,
copyrightAr: string | null,
copyrightEn: string | null,
columns: Record<string, any>[],
version: number,
};
export type HomeLogoData = {
mediaId: string,
image: ContentImageData | null,
nameAr: string,
nameEn: string | null,
link: string | null,
};
export type HomePageData = {
stores: ContentStoreData[],
storeCode: string,
tab: string,
sections: HomeSectionData[],
addable: string[],
footer: FooterData,
translationReady: boolean,
};
export type HomeSectionData = {
id: string,
type: string,
isVisible: boolean,
version: number,
settings: Record<string, any>,
translationUnread: boolean,
};
export type HomeSectionPageData = {
store: ContentStoreData,
section: HomeSectionData,
logos: HomeLogoData[],
icons: string[],
translationReady: boolean,
};
export type NamedChoiceData = {
id: string,
nameAr: string,
nameEn: string | null,
};
export type PopupData = {
id: string,
name: string,
titleAr: string,
titleEn: string | null,
textAr: string,
textEn: string | null,
buttonAr: string | null,
buttonEn: string | null,
link: string | null,
delaySeconds: number,
mediaId: string | null,
state: string,
startsAt: string | null,
endsAt: string | null,
version: number,
translationUnread: boolean,
};
export type PopupFormPageData = {
store: ContentStoreData,
storeTimezone: string,
popup: PopupData | null,
image: ContentImageData | null,
translationReady: boolean,
};
export type PopupRowData = {
id: string,
name: string,
state: string,
startsAt: string | null,
endsAt: string | null,
version: number,
};
export type PopupsPageData = {
stores: ContentStoreData[],
storeCode: string,
storeTimezone: string,
popups: PopupRowData[],
next: string | null,
};
export type ProductChoiceData = {
id: string,
nameAr: string,
nameEn: string | null,
archived: boolean,
};
export type RedirectRowData = {
oldPath: string,
newPath: string,
visits: number,
lastVisitAt: string | null,
check: string | null,
finalPath: string | null,
};
export type RedirectSheetPageData = {
uploadId: string,
lines: SheetLineData[],
counts: Record<string, number>,
applied: boolean,
tab: string,
};
export type RedirectsPageData = {
rows: RedirectRowData[],
next: string | null,
q: string,
checkWaits: boolean,
};
export type RunStateData = {
storeId: string,
code: string,
state: string,
};
export type SeoPageData = {
stores: ContentStoreData[],
storeCode: string,
siteNameAr: string | null,
siteNameEn: string | null,
titlePatternAr: string | null,
titlePatternEn: string | null,
descriptionAr: string | null,
descriptionEn: string | null,
shareImage: ContentImageData | null,
robotsLines: string,
sitemapBuiltAt: string | null,
sitemapUrls: number,
sitemapAddress: string,
rebuildWaits: boolean,
translationReady: boolean,
};
export type SheetLineData = {
line: number,
old: string,
new: string,
result: string,
reason: string | null,
};
export type SmartBarData = {
id: string,
scope: string,
categoryId: string | null,
runId: string | null,
messageAr: string,
messageEn: string,
link: string | null,
state: string,
startsAt: string | null,
endsAt: string | null,
};
export type ThemeData = {
id: string,
nameAr: string,
nameEn: string | null,
storeIds: string[],
startsLocal: string,
endsLocal: string,
version: number,
state: string,
states: RunStateData[],
translationUnread: boolean,
};
export type ThemeDateMatchData = {
storeId: string,
match: boolean,
};
export type ThemeFormPageData = {
stores: ContentStoreData[],
themeItem: ThemeData | null,
parts: ThemePartData[],
images: Record<string, ContentImageData>,
campaigns: NamedChoiceData[],
translationReady: boolean,
previewing: boolean,
};
export type ThemePartData = {
part: string,
textAr: string | null,
textEn: string | null,
link: string | null,
promises: ThemePromiseData[],
images: ThemePartImageData[],
};
export type ThemePartFillData = {
part: string,
filled: boolean,
};
export type ThemePartImageData = {
key: string,
mediaId: string,
};
export type ThemePromiseData = {
ar: string,
en: string | null,
};
export type ThemeRowData = {
id: string,
nameAr: string,
nameEn: string | null,
stores: RunStateData[],
startsLocal: string,
endsLocal: string,
zone: string | null,
parts: number,
state: string,
version: number,
};
export type ThemesPageData = {
themes: ThemeRowData[],
next: string | null,
};
