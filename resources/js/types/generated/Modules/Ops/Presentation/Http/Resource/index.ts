import type { MessageKind } from '../../../Public/Enums';
import type { ReportKind } from '../../../Application/Reports';
export type CustomerToMessageData = {
id: string,
firstName: string,
lastName: string,
email: string,
phone: string | null,
homeStoreCode: string,
};
export type DailyReportData = {
id: string,
storeId: string | null,
storeCode: string | null,
storeName: string,
day: string,
pastDay: boolean,
madeBy: string,
madeAt: string,
emailedAt: string | null,
language: string,
bytes: number,
downloadUrl: string,
};
export type DailyReportEmailData = {
storeId: string | null,
storeCode: string | null,
storeName: string,
on: boolean,
sendAt: string,
timezone: string,
nextDueAt: string | null,
};
export type DailyReportPageData = {
reports: DailyReportData[],
next: string | null,
};
export type InboxEntryData = {
id: string,
kind: MessageKind,
storeCode: string,
storeName: string,
title: string,
line: string,
link: string | null,
read: boolean,
createdAt: string,
};
export type InboxPageData = {
entries: InboxEntryData[],
next: string | null,
unread: number,
};
export type NoticeData = {
id: string,
storeCode: string,
titleAr: string,
titleEn: string,
bodyAr: string,
bodyEn: string,
link: string | null,
byBell: boolean,
byEmail: boolean,
status: string,
sendAt: string,
sendAtLocal: string,
changeable: boolean,
bellCount: number,
emailCount: number,
failedCount: number,
createdAt: string,
segmentId: string | null,
segmentNameAr: string | null,
segmentNameEn: string | null,
segmentMemberCount: number | null,
writtenBy: string,
startedAt: string | null,
finishedAt: string | null,
};
export type NoticesPageData = {
notices: NoticeData[],
next: string | null,
};
export type OpsChoicesData = {
notices: StoreChoiceData[],
messagesToOne: StoreChoiceData[],
messagesToMany: StoreChoiceData[],
subscribers: StoreChoiceData[],
dailyReports: StoreChoiceData[],
dailyReportsAllStores: boolean,
reports: ReportChoiceData[],
};
export type ReportChoiceData = {
kind: ReportKind,
stores: StoreChoiceData[],
allStores: boolean,
};
export type ReportColumnData = {
key: string,
label: string,
type: string,
};
export type ReportPreviewData = {
report: string,
locale: string,
rightToLeft: boolean,
title: string,
subtitle: string,
sheets: ReportSheetData[],
filename: string,
downloadUrl: string,
};
export type ReportSheetData = {
name: string,
columns: ReportColumnData[],
notes: string[],
rows: (string| number| null)[][],
more: boolean,
};
export type SegmentChoiceData = {
id: string,
nameAr: string,
nameEn: string,
memberCount: number,
};
export type SegmentsPageData = {
segments: SegmentChoiceData[],
next: string | null,
};
export type SmsCountriesData = {
countries: SmsCountryData[],
providers: string[],
};
export type SmsCountryData = {
countryCode: string,
name: string,
callingCode: string,
provider: string,
};
export type StaffMessageData = {
id: string,
storeCode: string,
audience: string,
customerId: string | null,
customerName: string | null,
segmentId: string | null,
segmentNameAr: string | null,
segmentNameEn: string | null,
segmentMemberCount: number | null,
titleAr: string,
titleEn: string,
bodyAr: string,
bodyEn: string,
byBell: boolean,
byEmail: boolean,
status: string,
sentBy: string,
bellCount: number,
emailCount: number,
failedCount: number,
createdAt: string,
finishedAt: string | null,
};
export type StaffMessagesPageData = {
messages: StaffMessageData[],
next: string | null,
};
export type StoreChoiceData = {
id: string,
code: string,
name: string,
isOn: boolean,
};
export type SubscriberData = {
id: string,
email: string | null,
locale: string,
kind: string,
confirmed: boolean,
createdAt: string,
confirmedAt: string | null,
};
export type SubscribersPageData = {
subscribers: SubscriberData[],
next: string | null,
};
export type UnreadData = {
unread: number,
};
