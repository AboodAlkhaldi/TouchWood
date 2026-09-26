export type AccountPage = {
email: string,
pendingEmail: string | null,
firstName: string,
lastName: string,
jobTitle: string,
dateOfBirth: string,
country: string,
address: string | null,
locale: string,
avatarUrl: string | null,
phone: string | null,
canChangeEmail: boolean,
notifications: NotificationSetting[],
countries: CountryOption[],
passwordMinLength: number,
sessions: StaffSessionRow[],
trustedBrowsers: TrustedBrowserRow[],
tab: string,
};
export type AddressBookStore = {
storeId: string,
storeCode: string,
storeName: string,
hasFormat: boolean,
fields: AddressFieldRow[],
addresses: AddressRow[],
limit: number,
full: boolean,
};
export type AddressFieldRow = {
key: string,
label: string,
required: boolean,
maxLength: number,
};
export type AddressFormatField = {
key: string,
labelAr: string,
labelEn: string,
required: boolean,
maxLength: number,
};
export type AddressFormatPage = {
stores: AddressFormatStore[],
storeId: string,
exists: boolean,
fields: AddressFormatField[],
displayTemplate: string,
maxFields: number,
maxLength: number,
};
export type AddressFormatStore = {
id: string,
code: string,
name: string,
hasFormat: boolean,
};
export type AddressRow = {
id: string,
label: string,
recipientName: string,
phone: string,
fields: Record<string, string>,
formatted: string,
isDefault: boolean,
isComplete: boolean,
};
export type ComingSoonPage = {
label: string,
};
export type CountryOption = {
code: string,
name: string,
ours: boolean,
};
export type CustomerAccountPage = {
tab: string,
firstName: string,
lastName: string,
email: string,
accountType: string,
locale: string,
phone: string | null,
emailVerified: boolean,
phoneVerified: boolean,
mayOrder: boolean,
homeStore: string,
passwordMinimumLength: number,
addresses: AddressBookStore[],
deletionDays: number,
};
export type CustomerAddressGroup = {
storeId: string,
storeName: string,
addresses: AddressRow[],
};
export type CustomerDetailsPage = {
customer: CustomerRow,
locale: string,
addresses: CustomerAddressGroup[],
mayBlock: boolean,
mayUnblock: boolean,
mayStartDeletion: boolean,
mayCancelDeletion: boolean,
deletionDays: number,
};
export type CustomerListPage = {
customers: CustomerRow[],
total: number,
page: number,
perPage: number,
search: string | null,
status: string | null,
accountType: string | null,
statuses: string[],
accountTypes: string[],
};
export type CustomerRegisterPage = {
minimumLength: number,
};
export type CustomerRow = {
id: string,
name: string,
email: string,
phone: string | null,
accountType: string,
status: string,
emailVerified: boolean,
phoneVerified: boolean,
deletionScheduledFor: string | null,
anonymized: boolean,
homeStore: string,
registeredAt: string,
};
export type CustomerSignInPage = {
rememberDays: number,
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
export type InviteStaffPage = {
savedRoles: RoleRow[],
savedPermissions: Record<string, string[]>,
permissions: EditorPermissionRow[],
adminPermissions: EditorPermissionRow[],
groups: PermissionGroupRow[],
stores: StoreOption[],
countries: CountryOption[],
maySetAdmin: boolean,
locale: string,
};
export type NotificationSetting = {
topic: string,
email: boolean,
panel: boolean,
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
permissions: RolePermissionRow[],
permissionsByRole: Record<string, string[]>,
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
export type StaffActionRow = {
name: string,
label: string,
group: string,
storeFree: boolean,
storeNames: string[] | null,
exception: boolean,
};
export type StaffGroup = {
key: string,
label: string,
staff: StaffRow[],
};
export type StaffListPage = {
groups: StaffGroup[],
total: number,
search: string | null,
status: string | null,
statuses: string[],
mayInvite: boolean,
};
export type StaffMemberPage = {
id: string,
name: string,
firstName: string,
lastName: string,
jobTitle: string | null,
email: string | null,
phone: string | null,
status: string,
locale: string,
dateOfBirth: string | null,
country: string | null,
address: string | null,
avatarUrl: string | null,
isAdmin: boolean,
isSuperAdmin: boolean,
roleId: string | null,
roleName: string,
allStores: boolean,
storeNames: string[],
actions: StaffActionRow[],
groups: PermissionGroupRow[],
mayEditProfile: boolean,
mayChangeEmail: boolean,
mayChangeRole: boolean,
mayDisable: boolean,
mayEnable: boolean,
mayResendInvitation: boolean,
mayCancelInvitation: boolean,
mayRefresh: boolean,
};
export type StaffRolePage = {
staffId: string,
staffName: string,
savedRoles: RoleRow[],
savedPermissions: Record<string, string[]>,
permissions: EditorPermissionRow[],
groups: PermissionGroupRow[],
stores: StoreOption[],
roleId: string | null,
personal: boolean,
accessLevel: string,
chosen: string[],
storeIds: string[],
exceptions: Record<string, string[]>,
};
export type StaffRow = {
id: string,
name: string,
roleName: string,
isAdmin: boolean,
jobTitle: string | null,
email: string | null,
status: string | null,
since: string | null,
};
export type StaffSessionRow = {
id: string,
ipAddress: string | null,
userAgent: string | null,
lastActivity: string,
isCurrent: boolean,
};
export type StoreOption = {
id: string,
name: string,
};
export type TrustedBrowserRow = {
id: string,
expiresAt: string,
};
export type VerifyEmailPage = {
email: string,
linkHours: number,
};
