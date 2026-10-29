import type { MessageKind } from '../../../Public/Enums';
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
export type UnreadData = {
unread: number,
};
