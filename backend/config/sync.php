<?php

return [

    'key_column' => 'uuid',

    'revision_column' => 'revision',

    'pull_limit' => 500,

    'tables' => [
        // Registered per module as v2 tables ship (see docs/FAZIL_ERP_REUSE.md
        // SPEC-PENDING notes). Must mirror frontend/src/services/syncTables.js.
    ],
];
