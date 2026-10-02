<?php

declare(strict_types=1);

// The names of B2B's settings, as the settings screen shows them (BankAccountSettings). They start
// empty, and a company sees the account only once all three are filled in (amendment 12(b)).
return [
    'module' => 'Companies',

    'bank.iban' => 'IBAN Companies Transfer To',
    'bank.name' => 'Bank Name',
    'bank.holder' => 'Account Holder',

    // The section's line (BankTransferLine, amendment 13(c)): bank transfer is on only while all
    // three are filled in.
    'bank_transfer.on' => 'Bank transfer: on',
    'bank_transfer.off' => 'Bank transfer: temporarily off — fill in all three to turn it on.',

    // The company form's minimums, in characters (FormRules, amendment 16(b)).
    'form.name_min' => 'Company Name: Fewest Characters',
    'form.cr_number_min' => 'CR Number: Fewest Characters',
    'form.tax_number_min' => 'Tax Number: Fewest Characters',
    'form.company_type_other_min' => '"Other" Type in Words: Fewest Characters',
    'form.answer_min' => 'A Written Answer: Fewest Characters',
];
