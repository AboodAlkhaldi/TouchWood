<?php

declare(strict_types=1);

// The names of B2B's settings, as the settings screen shows them (BankAccountSettings). They start
// empty, and a company sees the account only once all three are filled in (amendment 12(b)).
return [
    'module' => 'Companies',

    'bank.iban' => 'IBAN companies transfer to',
    'bank.name' => 'Bank name',
    'bank.holder' => 'Account holder',
];
