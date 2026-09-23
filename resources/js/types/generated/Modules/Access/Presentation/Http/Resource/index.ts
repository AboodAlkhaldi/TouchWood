export type ComingSoonPage = {
label: string,
};
export type EditorPermissionRow = {
name: string,
label: string,
group: string,
storeFree: boolean,
grantable: boolean,
};
export type EmailChangePage = {
token: string,
newEmail: string,
};
export type InvitationPage = {
token: string,
name: string,
email: string,
phone: string,
minimumLength: number,
};
export type PermissionGroupRow = {
key: string,
label: string,
};
export type ResetPasswordPage = {
token: string,
minimumLength: number,
};
export type RoleEditorPage = {
id: string | null,
nameAr: string,
nameEn: string,
level: string,
permissions: EditorPermissionRow[],
groups: PermissionGroupRow[],
chosen: string[],
holderCount: number,
};
export type RoleHolderRow = {
staffId: string,
name: string,
storeNames: string[] | null,
};
export type RolePage = {
id: string,
name: string,
nameAr: string,
nameEn: string,
level: string,
permissions: RolePermissionRow[],
groups: PermissionGroupRow[],
holderCount: number,
holders: RoleHolderRow[],
editable: boolean,
replacements: RoleRow[],
};
export type RolePermissionRow = {
name: string,
label: string,
group: string,
storeFree: boolean,
};
export type RoleRow = {
id: string,
name: string,
level: string,
permissionCount: number,
holderCount: number,
editable: boolean,
groups: string[],
};
export type RolesPage = {
roles: RoleRow[],
groups: PermissionGroupRow[],
mayCreate: boolean,
};
export type SignInCodePage = {
maskedPhone: string | null,
length: number,
trustDays: number,
resendIn: number,
action: string,
resendAction: string,
};
