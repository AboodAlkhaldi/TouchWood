<?php

declare(strict_types=1);

// The names of B2B's settings, as the settings screen shows them (BankAccountSettings). They start
// empty, and a company sees the account only once all three are filled in (amendment 12(b)).
return [
    'module' => 'Companies',

    'bank.iban' => 'IBAN companies transfer to',
    'bank.name' => 'Bank name',
    'bank.holder' => 'Account holder',

    // The section's line (BankTransferLine, amendment 13(c)): bank transfer is on only while all
    // three are filled in.
    'bank_transfer.on' => 'Bank transfer: on',
    'bank_transfer.off' => 'Bank transfer: temporarily off — fill in all three to turn it on.',
];
