export type AccessLevel = 'ALL_STORES' | 'SELECTED_STORES';
export type AccountType = 'INDIVIDUAL' | 'COMPANY';
export type CustomerStatus = 'ACTIVE' | 'BLOCKED';
export type PermissionAudience = 'ROLE' | 'EVERY_STAFF' | 'EVERY_CUSTOMER' | 'EVERY_GUEST';
export type PermissionGroup = 'staff_and_permissions' | 'customers' | 'store_settings' | 'media' | 'audit' | 'catalog' | 'pricing' | 'orders' | 'companies';
export type PermissionKind = 'PER_STORE' | 'GLOBAL';
export type StaffNotificationTopic = 'NEW_ORDERS' | 'COMPANY_APPLICATIONS' | 'LOW_STOCK' | 'CAMPAIGN_EXPIRY';
export type StaffStatus = 'INVITED' | 'ACTIVE' | 'DISABLED' | 'CANCELLED';
