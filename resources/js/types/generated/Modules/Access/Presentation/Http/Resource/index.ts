export type ComingSoonPage = {
label: string,
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
export type ResetPasswordPage = {
token: string,
minimumLength: number,
};
export type SignInCodePage = {
maskedPhone: string | null,
length: number,
trustDays: number,
resendIn: number,
action: string,
resendAction: string,
};
