import type { MessageKind } from '../../../Public/Enums';
export type CustomerToMessageData = {
id: string,
firstName: string,
lastName: string,
email: string | null,
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
};
export type NoticesPageData = {
notices: NoticeData[],
next: string | null,
};
export type SubscriberData = {
id: string,
email: string,
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
