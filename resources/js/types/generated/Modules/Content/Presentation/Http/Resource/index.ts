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
};
export type HomeSectionPageData = {
store: ContentStoreData,
section: HomeSectionData,
logos: HomeLogoData[],
icons: string[],
translationReady: boolean,
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
