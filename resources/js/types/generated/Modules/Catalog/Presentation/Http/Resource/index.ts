export type AttributeData = {
id: string,
nameAr: string,
nameEn: string,
kind: string,
unitAr: string | null,
unitEn: string | null,
isColour: boolean,
active: boolean,
position: number,
values: number,
kindLocked: boolean,
inVariation: boolean,
inUse: boolean,
};
export type AttributePage = {
attribute: AttributeData,
values: ValueData[],
mayChange: boolean,
};
export type AttributesPage = {
attributes: AttributeData[],
mayChange: boolean,
};
export type BrandData = {
id: string,
number: number,
nameAr: string,
nameEn: string,
slugAr: string | null,
slugEn: string | null,
agencyType: string,
showInDefaultListings: boolean,
isDefault: boolean,
active: boolean,
position: number,
originCountry: string | null,
logoMediaId: string | null,
logo: string | null,
descriptionAr: string,
descriptionEn: string,
products: number,
};
export type BrandsPage = {
brands: BrandData[],
mayChange: boolean,
reached: ReachedProductData[] | null,
reachedFor: string | null,
countries: CountryOptionData[],
};
export type CategoriesPage = {
categories: CategoryData[],
storeCode: string | null,
storeName: string | null,
stores: StoreOptionData[],
mayManage: boolean,
mayRank: boolean,
reached: ReachedProductData[] | null,
reachedFor: string | null,
};
export type CategoryData = {
id: string,
parentId: string | null,
nameAr: string,
nameEn: string,
slugAr: string | null,
slugEn: string | null,
active: boolean,
deactivatedWithParent: boolean,
imageMediaId: string | null,
image: string | null,
productsHere: number,
products: number,
storeRank: number | null,
baseRank: number | null,
};
export type CountryOptionData = {
code: string,
name: string,
ours: boolean,
};
export type LabelData = {
id: string,
nameAr: string,
nameEn: string,
tone: string,
active: boolean,
position: number,
products: number,
};
export type LabelsPage = {
labels: LabelData[],
mayChange: boolean,
};
export type NoResultSearchData = {
query: string,
storeName: string,
storeCode: string,
locale: string,
times: number,
lastSearchedAt: string,
};
export type ReachedProductData = {
id: string,
nameAr: string,
nameEn: string | null,
stage: string,
categoryId: string | null,
};
export type SearchWordsPage = {
pairs: WordPairData[],
mayChange: boolean,
searches: NoResultSearchData[] | null,
page: number,
more: boolean,
storeCode: string | null,
stores: StoreOptionData[],
storeTimezone: string | null,
};
export type StoreOptionData = {
id: string,
code: string,
name: string,
isActive: boolean,
};
export type ValueData = {
id: string,
nameAr: string,
nameEn: string,
swatch: string | null,
active: boolean,
position: number,
inUse: boolean,
};
export type VariationData = {
id: string,
nameAr: string,
nameEn: string,
active: boolean,
attributeIds: string[],
builtOn: boolean,
products: number,
};
export type VariationsPage = {
variations: VariationData[],
attributes: AttributeData[],
mayChange: boolean,
};
export type WarrantiesPage = {
warranties: WarrantyData[],
mayChange: boolean,
};
export type WarrantyData = {
id: string,
nameAr: string,
nameEn: string,
periodMonths: number | null,
termsAr: string,
termsEn: string,
active: boolean,
products: number,
};
export type WordPairData = {
id: string,
wordA: string,
wordB: string,
};
