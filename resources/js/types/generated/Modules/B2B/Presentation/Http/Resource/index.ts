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
