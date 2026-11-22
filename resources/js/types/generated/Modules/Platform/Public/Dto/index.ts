export type HomeAction = {
readonly label: string,
readonly href: string,
readonly fields: Record<string, string>,
readonly busy: string,
readonly failed: string,
};
export type HomeCell = {
readonly value: HomeValue | null,
readonly text: string | null,
readonly badge: string | null,
};
export type HomeChange = {
readonly percent: number | null,
};
export type HomeColumn = {
readonly label: string,
readonly end: boolean,
};
export type HomeMetric = {
readonly label: string,
readonly lines: HomeMetricLine[],
readonly total: HomeMetricLine | null,
readonly href: string | null,
};
export type HomeMetricLine = {
readonly store: string | null,
readonly value: HomeValue | null,
readonly change: HomeChange | null,
readonly trend: number[],
readonly note: string | null,
};
export type HomePoint = {
readonly at: string,
readonly values: Record<string, number | null>,
};
export type HomeSeries = {
readonly lines: HomeSeriesLine[],
readonly points: HomePoint[],
readonly byMonth: boolean,
};
export type HomeSeriesLine = {
readonly key: string,
readonly label: string,
readonly unit: string,
readonly dotted: boolean,
readonly money: MoneyData | null,
};
export type HomeSplit = {
readonly label: string,
readonly shape: string,
readonly parts: HomeSplitPart[],
readonly total: HomeValue | null,
};
export type HomeSplitPart = {
readonly label: string,
readonly value: HomeValue,
readonly share: number,
readonly change: HomeChange | null,
};
export type HomeStorePanel = {
readonly storeCode: string,
readonly storeName: string,
readonly series: HomeSeries | null,
readonly splits: HomeSplit[],
readonly table: HomeTable | null,
};
export type HomeTable = {
readonly columns: HomeColumn[],
readonly rows: HomeTableRow[],
readonly empty: string,
};
export type HomeTableRow = {
readonly title: string,
readonly cells: HomeCell[],
readonly detail: string | null,
readonly photo: string | null,
readonly href: string | null,
};
export type HomeValue = {
readonly unit: string,
readonly value: number,
readonly money: MoneyData | null,
};
export type MoneyData = {
readonly text: string,
readonly minor: number,
};
export type ShopPolicyLinkData = {
readonly policyId: string,
readonly version: number,
readonly url: string,
};
