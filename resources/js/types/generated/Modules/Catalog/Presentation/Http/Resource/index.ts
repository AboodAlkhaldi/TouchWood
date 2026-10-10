export type AttributeChoiceData = {
id: string,
nameAr: string,
nameEn: string,
kind: string,
unitAr: string | null,
unitEn: string | null,
isColour: boolean,
active: boolean,
values: ChoiceValueData[],
};
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
export type BrandOptionData = {
id: string,
nameAr: string,
nameEn: string,
isDefault: boolean,
active: boolean,
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
export type CategoryOptionData = {
id: string,
pathAr: string,
pathEn: string,
active: boolean,
};
export type ChoiceValueData = {
id: string,
nameAr: string,
nameEn: string,
swatch: string | null,
active: boolean,
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
export type PhotoData = {
mediaId: string,
thumb: string | null,
state: string,
};
export type ProductCountsData = {
variants: number,
photos: number,
searchWords: number,
filterValues: number,
related: number,
goesWith: number,
};
export type ProductHeadData = {
id: string,
nameAr: string,
nameEn: string | null,
slugAr: string | null,
slugEn: string | null,
stage: string,
archivedFrom: string | null,
brandId: string,
brandNameAr: string,
brandNameEn: string,
categoryId: string | null,
categoryPathAr: string | null,
categoryPathEn: string | null,
warrantyId: string | null,
attributeSetId: string | null,
attributeSetNameAr: string | null,
attributeSetNameEn: string | null,
setAttributeIds: string[],
descriptionAr: string,
descriptionEn: string,
hiddenByCategory: boolean,
hiddenByBrand: boolean,
codes: string[],
counts: ProductCountsData,
};
export type ProductPage = {
product: ProductHeadData,
tab: string,
missing: string[],
gallery: PhotoData[],
mayUpdate: boolean,
mayPublish: boolean,
mayArchive: boolean,
mayCorrectCode: boolean,
brands: BrandOptionData[] | null,
categories: CategoryOptionData[] | null,
warranties: WarrantyOptionData[] | null,
variations: VariationOptionData[] | null,
variants: VariantData[] | null,
attributes: AttributeChoiceData[] | null,
searchWords: string[] | null,
filterValueIds: string[] | null,
related: RelatedData[] | null,
found: ProductRowData[] | null,
};
export type ProductRowData = {
id: string,
nameAr: string,
nameEn: string | null,
codes: string[],
stage: string,
brandNameAr: string,
brandNameEn: string,
categoryNameAr: string | null,
categoryNameEn: string | null,
photo: string | null,
variants: number,
onIn: string[],
storeState: string | null,
storeVariantsOn: number,
};
export type ProductsPage = {
products: ProductRowData[],
more: boolean,
after: string | null,
search: string | null,
stage: string | null,
categoryId: string | null,
brandId: string | null,
storeState: string | null,
storeCode: string | null,
stores: StoreOptionData[],
mayCreate: boolean,
brands: BrandOptionData[],
categories: CategoryOptionData[],
};
export type ReachedProductData = {
id: string,
nameAr: string,
nameEn: string | null,
stage: string,
categoryId: string | null,
};
export type RelatedData = {
kind: string,
productId: string,
nameAr: string,
nameEn: string | null,
codes: string[],
stage: string,
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
export type VariantData = {
id: string,
code: string,
position: number,
archived: boolean,
values: VariantValueData[],
details: VariantDetailData[],
weightGrams: number | null,
lengthMm: number | null,
widthMm: number | null,
heightMm: number | null,
photos: PhotoData[],
};
export type VariantDetailData = {
attributeId: string,
textAr: string | null,
textEn: string | null,
number: string | null,
};
export type VariantValueData = {
attributeId: string,
valueId: string,
nameAr: string,
nameEn: string,
swatch: string | null,
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
export type VariationOptionData = {
id: string,
nameAr: string,
nameEn: string,
attributeIds: string[],
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
export type WarrantyOptionData = {
id: string,
nameAr: string,
nameEn: string,
periodMonths: number | null,
active: boolean,
};
export type WordPairData = {
id: string,
wordA: string,
wordB: string,
};
