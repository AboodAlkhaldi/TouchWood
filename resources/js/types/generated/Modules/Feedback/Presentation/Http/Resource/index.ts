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
};
export type ShopVariantValueData = {
attribute: string,
value: string,
};
export type ShopWishlistData = {
saved: boolean,
};
