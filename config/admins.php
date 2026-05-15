<?php

/*
|--------------------------------------------------------------------------
| Admin Users
|--------------------------------------------------------------------------
|
| Operator accounts seeded into stocs-auth by AdminUsersSeeder. Each entry
| becomes a super-admin user with approved access on every platform.
|
| Each slot reads its secrets from env. Entries with a missing email or
| password are skipped, so unused slots stay inert in production. Add a
| new admin by appending a new array entry and the matching env vars.
|
*/

return [
    [
        'email' => env('ADMIN_1_EMAIL'),
        'password' => env('ADMIN_1_PASSWORD'),
        'name' => env('ADMIN_1_NAME', 'Admin'),
    ],
    [
        'email' => env('ADMIN_2_EMAIL'),
        'password' => env('ADMIN_2_PASSWORD'),
        'name' => env('ADMIN_2_NAME', 'Admin'),
    ],
];
