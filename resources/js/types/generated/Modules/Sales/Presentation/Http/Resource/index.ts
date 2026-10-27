export type CustomerOrderData = {
orderId: string,
number: string,
storeId: string,
placedAt: string,
status: string,
shipment: string,
payment: string,
customerStatus: string,
total: MoneyData,
shortOfStock: boolean,
mayShipUnpaid: boolean,
};
export type CustomerOrdersSectionData = {
orders: CustomerOrderData[],
};
export type MoneyData = {
amount: string,
currency: string,
};
