
<?php

return [
    'tenant_id' => env('MS_TENANT_ID'),
    'client_id' => env('MS_CLIENT_ID'),
    'client_secret' => env('MS_CLIENT_SECRET'),
    'mailbox' => env('MS_MAILBOX'),
    'enabled' => env('MS_GRAPH_ENABLED', false),
];
