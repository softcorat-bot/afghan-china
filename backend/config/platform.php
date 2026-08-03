<?php

return [

    /*
     | The single, immutable root identity of the SaaS platform. Platform-critical
     | operations (tenant/branch provisioning, licensing, global settings, …) are
     | reserved to this account and cannot be granted through roles or permissions.
     */
    'owner_email' => env('PLATFORM_OWNER_EMAIL', 'support@briskcodes.com'),

    /*
     | Branch creation/deletion is reserved to the Platform Owner by default. A
     | tenant only gains it if the Platform Owner flips branch_self_service on for
     | that organization.
     */
    'branch_self_service_default' => false,

    /*
     | Counter PIN terminal. When true, /pin/staff lists the cashiers who hold a
     | PIN (name + initials only, POS-sellers only) so the register screen can
     | show tap-to-sign-in tiles. Set POS_PIN_LOGIN=false to close that door and
     | force email + password for everyone.
     */
    'pin_login' => (bool) env('POS_PIN_LOGIN', true),

    /*
     | Demo business data: two weeks of retail sales, wholesale invoices, the
     | supply-chain history, loyalty customers and shop expenses.
     |
     | It defaults ON locally and **OFF in production**, because seeding
     | hundreds of invented sales into a real shop's books would quietly
     | corrupt every report the owner reads. A production install that wants
     | the demo (for a showcase, or a client walkthrough) opts in explicitly
     | with SEED_DEMO_DATA=true.
     */
    'demo_data' => (bool) env('SEED_DEMO_DATA', env('APP_ENV') !== 'production'),
];
