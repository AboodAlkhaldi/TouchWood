<?php

declare(strict_types=1);

namespace Tests\Modules\Payments\Support;

/**
 * MyFatoorah's answers, in the shapes its test server gave on 2026-10-28 (payments.md §9.8). No test calls it.
 */
final class MyFatoorahAnswers
{
    /**
     * @return array<string, mixed>
     */
    public static function session(string $id = 'KWT-a5f60574-ddf0-46b9-a30e-102b231604aa', string $expiry = '2027-01-01T10:15:00.6462705Z'): array
    {
        return ['IsSuccess' => true, 'Message' => 'Created Successfully!', 'ValidationErrors' => null, 'Data' => [
            'SessionId' => $id,
            'SessionExpiry' => $expiry,
            'EncryptionKey' => 'the-session-encryption-key',
            'OperationType' => 'PAY',
            'Order' => ['Amount' => 10, 'Currency' => 'SAR', 'ExternalIdentifier' => null],
            'Customer' => ['Reference' => null, 'Cards' => null],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function refusal(string $message = 'invalid data!', string $error = 'Your request has invalid payment options'): array
    {
        return ['IsSuccess' => false, 'Message' => $message, 'ValidationErrors' => [['Name' => 'PaymentMethods', 'Error' => $error]], 'Data' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentData(string $status = 'SUCCESS', string $invoiceStatus = 'PAID', string $errorCode = '', string $errorMessage = ''): array
    {
        return [
            'Invoice' => ['Id' => '7320290', 'Status' => $invoiceStatus, 'Reference' => '2026246844', 'CreationDate' => '2026-10-28T19:32:58.5700000Z', 'ExpirationDate' => '2026-10-28T19:47:27.0000000Z', 'ExternalIdentifier' => '01k8attempt', 'UserDefinedField' => '', 'MetaData' => null],
            'Transaction' => [
                'Id' => '168173', 'Status' => $status, 'PaymentMethod' => 'VISA/MASTER', 'PaymentId' => '07077320290391998273', 'ReferenceId' => '630119168173', 'TrackId' => '28-10-2026_3919982', 'AuthorizationId' => '168173',
                'TransactionDate' => '2026-10-28T19:33:08.2470000Z', 'ECI' => '05', 'NetworkTransactionId' => '123456789012345', 'AgreementId' => '', 'IP' => ['Address' => '88.253.45.0', 'Country' => 'Türkiye'],
                'Error' => ['Code' => $errorCode, 'Message' => $errorMessage, 'ResponseCode' => '00', 'MerchantAdviceCode' => ''],
                'Card' => ['NameOnCard' => 'test test', 'Number' => '446404xxxxxx0007', 'Token' => 'a-card-token', 'PanHash' => 'a-pan-hash', 'ExpiryMonth' => '02', 'ExpiryYear' => '29', 'Brand' => 'Visa', 'Issuer' => 'Banque Saudi Fransi', 'IssuerCountry' => 'SAU', 'FundingMethod' => 'debit'],
            ],
            'Customer' => ['Name' => 'Anonymous', 'Mobile' => '+966500000000', 'Email' => 'buyer@example.test'],
            'Amount' => ['BaseCurrency' => 'KWD', 'ValueInBaseCurrency' => '0.81', 'ServiceCharge' => '0.101', 'ServiceChargeVAT' => '0.015', 'ReceivableAmount' => '0.694', 'DisplayCurrency' => 'SAR', 'ValueInDisplayCurrency' => '10.10', 'PayCurrency' => 'KWD', 'ValueInPayCurrency' => '0.81'],
            'Suppliers' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(string $status = 'SUCCESS', string $invoiceStatus = 'PAID', string $errorCode = '', string $errorMessage = ''): array
    {
        return ['IsSuccess' => true, 'Message' => '', 'ValidationErrors' => null, 'Data' => self::paymentData($status, $invoiceStatus, $errorCode, $errorMessage)];
    }

    /**
     * A notice of a changed payment (notes §5.3), and the signature MyFatoorah would put on it (notes §5.4).
     *
     * @return array{body: string, signature: string}
     */
    public static function notice(string $secret, string $reference = 'evt-1', string $status = 'SUCCESS', string $invoiceStatus = 'PAID', ?string $external = '01k8attempt', int $code = 1): array
    {
        $body = (string) json_encode([
            'Event' => ['Code' => $code, 'Name' => 'PAYMENT_STATUS_CHANGED', 'CountryIsoCode' => 'SAU', 'CreationDate' => '2026-10-28T19:33:08Z', 'Reference' => $reference],
            'Data' => [
                'Invoice' => ['Id' => '7320290', 'Status' => $invoiceStatus, 'Reference' => '2026246844', 'ExternalIdentifier' => $external],
                'Transaction' => ['Id' => '168173', 'Status' => $status, 'PaymentId' => '07077320290391998273', 'PaymentMethod' => 'VISA/MASTER', 'IP' => ['Address' => '88.253.45.0'], 'Card' => ['Number' => '446404xxxxxx0007', 'Brand' => 'Visa', 'Token' => 'a-card-token']],
                'Customer' => ['Name' => 'A Buyer', 'Mobile' => '+966500000000', 'Email' => 'buyer@example.test'],
                'Amount' => ['ValueInDisplayCurrency' => '10.10', 'DisplayCurrency' => 'SAR'],
            ],
        ]);

        return ['body' => $body, 'signature' => self::signature($secret, $invoiceStatus, $status, $external)];
    }

    public static function signature(string $secret, string $invoiceStatus = 'PAID', string $status = 'SUCCESS', ?string $external = '01k8attempt'): string
    {
        return base64_encode(hash_hmac(
            'sha256',
            'Invoice.Id=7320290,Invoice.Status='.$invoiceStatus.',Transaction.Status='.$status.',Transaction.PaymentId=07077320290391998273,Invoice.ExternalIdentifier='.($external ?? ''),
            $secret,
            true,
        ));
    }
}
