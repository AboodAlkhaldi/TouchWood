export type FavouriteItemData = {
product: ProductCardData,
savedBy: number,
};
export type ProductCardData = {
id: string,
name: string,
href: string | null,
photo: string | null,
sold: boolean,
archived: boolean,
removed: boolean,
};
export type QuestionItemData = {
id: string,
product: ProductCardData,
author: ShopAuthorData,
body: string,
locale: string,
status: string,
answer: string | null,
answeredBy: string | null,
answeredAt: string | null,
reason: string | null,
askedAt: string,
};
export type ReviewItemData = {
id: string,
product: ProductCardData,
variant: ShopVariantValueData[],
author: ShopAuthorData,
rating: number,
body: string,
locale: string,
status: string,
mark: string,
reason: string | null,
writtenAt: string,
decidedBy: string | null,
decidedAt: string | null,
};
export type ShopAuthorData = {
firstName: string,
lastInitial: string,
deleted: boolean,
};
export type ShopQuestionData = {
questionId: string,
author: ShopAuthorData,
body: string,
locale: string,
answer: string,
store: string,
answeredAt: string,
};
export type ShopQuestionsData = {
questions: ShopQuestionData[],
next: string | null,
canAsk: boolean,
};
export type ShopReviewData = {
reviewId: string,
author: ShopAuthorData,
rating: number,
body: string,
locale: string,
mark: string,
variant: ShopVariantValueData[],
writtenAt: string,
};
export type ShopReviewsData = {
average: number | null,
count: number,
reviews: ShopReviewData[],
next: string | null,
canWrite: boolean,
stars: ShopStarData[],
star: number | null,
};
export type ShopStarData = {
star: number,
count: number,
};
export type ShopVariantValueData = {
attribute: string,
value: string,
};
export type ShopWishlistData = {
saved: boolean,
};
export type StoreOptionData = {
code: string,
name: string,
isActive: boolean,
};
export type SupportListPage = {
reviews: ReviewItemData[],
questions: QuestionItemData[],
favourites: FavouriteItemData[],
status: string | null,
next: string | null,
mayAct: boolean,
storeCode: string,
storeName: string,
storeIsActive: boolean,
storeTimezone: string,
stores: StoreOptionData[],
};
export type WishlistItemData = {
product: ProductCardData,
savedAt: string,
};
export type WishlistSectionData = {
stores: WishlistStoreData[],
};
export type WishlistStoreData = {
name: string,
isOpen: boolean,
items: WishlistItemData[],
};
