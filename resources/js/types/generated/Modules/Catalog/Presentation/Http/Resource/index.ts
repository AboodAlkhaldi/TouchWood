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
values: number,
kindLocked: boolean,
inProducts: boolean,
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
mayChooseAudience: boolean,
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
forIndividuals: boolean,
forCompanies: boolean,
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
export type ImportFileRowData = {
id: string,
fileName: string,
uploadedBy: string | null,
uploadedAt: string,
products: number,
state: string,
};
export type ImportGuidePage = {
html: string,
};
export type ImportHeadData = {
id: string,
fileName: string,
state: string,
failure: string | null,
withPhotos: boolean,
uploadedBy: string | null,
uploadedAt: string,
products: number,
};
export type ImportNameData = {
id: string,
kind: string,
written: string,
attribute: string | null,
attributeKind: string | null,
decision: string | null,
targetId: string | null,
nameAr: string | null,
nameEn: string | null,
products: number,
matches: number,
slugAr: string | null,
slugEn: string | null,
addressTaken: boolean,
valueAttributeId: string | null,
};
export type ImportPage = {
import: ImportHeadData,
part: string,
waiting: ImportWaitingData,
names: ImportNameData[] | null,
products: ImportProductData[] | null,
page: number,
more: boolean,
brands: BrandOptionData[] | null,
categories: CategoryOptionData[] | null,
warranties: WarrantyOptionData[] | null,
attributes: AttributeChoiceData[] | null,
meansCategories: CategoryOptionData[] | null,
takenCategories: string[] | null,
toAccept: number,
};
export type ImportProblemData = {
at: string,
problem: string,
};
export type ImportProductData = {
id: string,
number: number,
nameAr: string,
nameEn: string | null,
codes: string[],
conflictProductId: string | null,
conflictNameAr: string | null,
conflictNameEn: string | null,
decision: string | null,
newCodes: Record<string, string> | null,
state: string,
productId: string | null,
changed: boolean,
missing: string[],
addressTaken: string[],
onSale: boolean,
sale: string | null,
refusal: string | null,
codeChange: boolean,
attributeChange: boolean,
heldCodes: string[],
};
export type ImportWaitingData = {
names: number,
codes: number,
addresses: number,
sales: number,
codeChanges: number,
attributeChanges: number,
};
export type ImportsPage = {
files: ImportFileRowData[],
page: number,
more: boolean,
problems: ImportProblemData[] | null,
problemsTotal: number | null,
};
export type LabelChoiceData = {
id: string,
nameAr: string,
nameEn: string,
tone: string,
active: boolean,
};
export type LabelData = {
id: string,
nameAr: string,
nameEn: string,
tone: string,
active: boolean,
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
variantAttributeIds: string[],
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
variants: VariantData[] | null,
attributes: AttributeChoiceData[] | null,
searchWords: string[] | null,
filterValueIds: string[] | null,
related: RelatedData[] | null,
found: ProductRowData[] | null,
stores: StoreCardData[] | null,
labels: LabelChoiceData[] | null,
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
export type ShopAddressData = {
canonical: string,
alternate: string | null,
alternateLocale: string | null,
noindex: boolean,
};
export type ShopBrandPage = {
name: string,
slug: string,
logo: Record<string, Record<string, string>> | null,
description: Record<string, any>,
products: ShopCardData[],
next: string | null,
address: ShopAddressData,
};
export type ShopCardData = {
productId: string,
name: string,
slug: string,
photo: Record<string, Record<string, string>> | null,
labels: ShopLabelData[],
lastPieces: boolean,
};
export type ShopCategoryPage = {
name: string,
slug: string,
path: ShopLinkData[],
children: ShopLinkData[],
products: ShopCardData[],
next: string | null,
filters: ShopFiltersData,
address: ShopAddressData,
};
export type ShopChoiceData = {
id: string,
name: string,
isColour: boolean,
values: ShopChoiceValueData[],
};
export type ShopChoiceValueData = {
id: string,
name: string,
swatch: string | null,
onSale: boolean,
};
export type ShopDetailData = {
name: string,
value: string,
};
export type ShopFilterChoiceData = {
id: string,
name: string,
swatch: string | null,
count: number,
chosen: boolean,
};
export type ShopFilterGroupData = {
id: string,
name: string,
choices: ShopFilterChoiceData[],
};
export type ShopFiltersData = {
brands: ShopFilterChoiceData[],
attributes: ShopFilterGroupData[],
sides: ShopFilterChoiceData[],
sort: string,
};
export type ShopFrameData = {
menu: ShopMenuItemData[],
words: Record<string, string>,
};
export type ShopLabelData = {
name: string,
tone: string,
};
export type ShopLinkData = {
name: string,
slug: string,
};
export type ShopMenuItemData = {
id: string,
name: string,
slug: string,
children: ShopMenuItemData[],
};
export type ShopProductPage = {
productId: string,
name: string,
slug: string,
available: boolean,
description: Record<string, any>,
photos: Record<string, Record<string, string>>[],
labels: ShopLabelData[],
path: ShopLinkData[],
brand: ShopLinkData | null,
choices: ShopChoiceData[],
variants: ShopVariantData[],
warranty: ShopWarrantyData | null,
selling: ShopSellingData | null,
goesWith: ShopCardData[],
mayAlsoLike: ShopCardData[],
address: ShopAddressData,
};
export type ShopSearchPage = {
query: string,
total: number,
products: ShopCardData[],
next: string | null,
address: ShopAddressData,
};
export type ShopSellingData = {
retailMinimum: number,
retailMaximum: number | null,
wholesaleMinimum: number | null,
wholesaleMaximum: number | null,
};
export type ShopVariantData = {
id: string,
values: Record<string, string>,
sellsRetail: boolean,
sellsWholesale: boolean,
lastPieces: boolean,
photos: Record<string, Record<string, string>>[],
details: ShopDetailData[],
weightGrams: number | null,
lengthMm: number | null,
widthMm: number | null,
heightMm: number | null,
};
export type ShopWarrantyData = {
name: string,
periodMonths: number | null,
terms: Record<string, any>,
};
export type StoreCardData = {
storeId: string,
code: string,
name: string,
active: boolean,
chosen: boolean,
notAvailable: boolean,
retailMinimum: number,
retailMaximum: number | null,
wholesaleMinimum: number | null,
wholesaleMaximum: number | null,
labelIds: string[],
variants: StoreVariantData[],
mayChoose: boolean,
maySell: boolean,
mayUnavailable: boolean,
mayLabel: boolean,
};
export type StoreFileItemData = {
id: string,
number: number,
code: string,
price: string,
stock: number | null,
state: string,
standing: string | null,
productId: string | null,
productNameAr: string | null,
productNameEn: string | null,
missing: string[],
lines: string[],
};
export type StoreFilePage = {
id: string,
fileName: string,
uploadedAt: string,
storeCode: string,
storeName: string,
storeTimezone: string,
pricesKept: boolean,
items: StoreFileItemData[],
};
export type StoreFileRowData = {
id: string,
fileName: string,
uploadedAt: string,
items: number,
open: number,
};
export type StoreFilesPage = {
files: StoreFileRowData[],
page: number,
more: boolean,
total: number,
storeCode: string,
storeName: string,
storeTimezone: string,
stores: StoreOptionData[],
problems: ImportProblemData[] | null,
problemsTotal: number | null,
};
export type StoreOptionData = {
id: string,
code: string,
name: string,
isActive: boolean,
};
export type StoreVariantData = {
variantId: string,
on: boolean,
retail: boolean,
wholesale: boolean,
notAvailable: boolean,
};
export type ValueData = {
id: string,
nameAr: string,
nameEn: string,
swatch: string | null,
active: boolean,
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
