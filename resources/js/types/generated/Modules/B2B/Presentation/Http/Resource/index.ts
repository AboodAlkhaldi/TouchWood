export type CompanyAnswerData = {
requestId: string,
text: string | null,
mediaId: string | null,
};
export type CompanyApplicationData = {
id: string,
reference: string,
state: string,
values: CompanyValuesData,
submittedAt: string | null,
decidedAt: string | null,
decisionReason: string | null,
documents: CompanyFileData[],
flags: CompanyFlagData[],
requests: CompanyRequestData[],
answers: CompanyAnswerData[],
};
export type CompanyBankAccountData = {
iban: string,
bank: string,
holder: string,
};
export type CompanyDraftData = {
id: string,
values: CompanyValuesData,
typeNoLongerAccepted: boolean,
documents: CompanyFileData[],
flags: CompanyFlagData[],
requests: CompanyRequestData[],
answers: CompanyAnswerData[],
};
export type CompanyFieldRuleData = {
min: number,
max: number,
oneLine: boolean,
characters: string | null,
};
export type CompanyFileData = {
documentTypeId: string,
documentTypeNameAr: string | null,
documentTypeNameEn: string | null,
mediaId: string,
fileName: string,
uploadedAt: string,
noLongerAccepted: boolean,
};
export type CompanyFlagData = {
field: string | null,
documentTypeId: string | null,
};
export type CompanyPage = {
stage: string | null,
company: CompanyStatusData | null,
draft: CompanyDraftData | null,
companyTypes: CompanyTypeOptionData[],
documentTypes: CompanyTypeOptionData[],
history: CompanyApplicationData[],
bankAccount: CompanyBankAccountData | null,
maxFileBytes: number,
formRules: Record<string, CompanyFieldRuleData>,
savedAddresses: CompanySavedAddressData[],
};
export type CompanyRequestData = {
id: string,
kind: string,
label: string,
};
export type CompanySavedAddressData = {
id: string,
storeNameAr: string,
storeNameEn: string,
label: string,
formatted: string,
isComplete: boolean,
};
export type CompanyStatusData = {
id: string,
details: CompanyValuesData,
status: string,
statusReason: string | null,
statusChangedAt: string | null,
mayOrder: boolean,
};
export type CompanyTypeOptionData = {
id: string,
nameAr: string,
nameEn: string,
greyed: boolean,
required: boolean,
};
export type CompanyValuesData = {
name: string | null,
companyTypeId: string | null,
companyTypeNameAr: string | null,
companyTypeNameEn: string | null,
companyTypeOther: string | null,
crNumber: string | null,
taxNumber: string | null,
address: string | null,
addressId: string | null,
note: string | null,
};
export type StaffAnswerData = {
requestId: string,
label: string | null,
text: string | null,
mediaId: string | null,
fileName: string | null,
};
export type StaffApplicationData = {
id: string,
reference: string,
state: string,
values: CompanyValuesData,
submittedAt: string | null,
decidedAt: string | null,
decidedBy: string | null,
decisionReason: string | null,
documents: CompanyFileData[],
flags: CompanyFlagData[],
requests: CompanyRequestData[],
answers: StaffAnswerData[],
typeDeactivatedSinceSent: boolean,
};
export type StaffCompanyActionsData = {
mayOpenDocuments: boolean,
mayApprove: boolean,
approveRefusal: string | null,
mayReject: boolean,
maySuspend: boolean,
mayReinstate: boolean,
mayCorrectType: boolean,
mayChooseOther: boolean,
};
export type StaffCompanyData = {
id: string,
values: CompanyValuesData,
status: string,
statusBeforeSuspension: string | null,
statusReason: string | null,
statusChangedAt: string | null,
statusChangedBy: string | null,
storeName: string,
mayOrder: boolean,
};
export type StaffCompanyListPage = {
companies: StaffCompanyRowData[],
total: number,
page: number,
perPage: number,
search: string | null,
status: string | null,
storeId: string | null,
statuses: string[],
stores: StaffStoreOptionData[],
};
export type StaffCompanyPage = {
company: StaffCompanyData,
holder: StaffHolderData | null,
applications: StaffApplicationData[],
actions: StaffCompanyActionsData,
typeChoices: StaffTypeChoiceData[],
};
export type StaffCompanyRowData = {
id: string,
name: string,
status: string,
storeName: string,
waitingSince: string | null,
typeDeactivatedSinceSent: boolean,
statusChangedAt: string | null,
};
export type StaffHolderData = {
name: string,
email: string,
phone: string | null,
emailVerified: boolean,
phoneVerified: boolean,
anonymized: boolean,
};
export type StaffStoreOptionData = {
id: string,
name: string,
};
export type StaffTypeActionsData = {
mayReadCompanyTypes: boolean,
mayReadDocumentTypes: boolean,
mayAdd: boolean,
mayUpdate: boolean,
mayDeactivate: boolean,
mayDeactivateIntoNew: boolean,
mayTransfer: boolean,
mayMarkReviewed: boolean,
};
export type StaffTypeChoiceData = {
id: string,
nameAr: string,
nameEn: string,
active: boolean,
};
export type StaffTypeListPage = {
kind: string,
storeName: string,
copiedNotReviewed: boolean,
types: StaffTypeRowData[],
actions: StaffTypeActionsData,
};
export type StaffTypeRowData = {
id: string,
nameAr: string,
nameEn: string,
position: number,
active: boolean,
inactiveDisplay: string | null,
required: boolean | null,
holders: number | null,
};
