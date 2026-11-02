<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Export limits
    |--------------------------------------------------------------------------
    |
    | Exports stream straight from the database so memory stays flat, but a
    | browser download still has to finish in a reasonable time. Excel exports
    | chunk through the query; PDF renders a fixed paper document so it gets a
    | stricter cap. Both limits can be overridden at runtime.
    |
    */
    'exports' => [
        'excel_max_rows' => (int) env('CORE_EXPORT_EXCEL_MAX_ROWS', 100000),
        'pdf_max_rows' => (int) env('CORE_EXPORT_PDF_MAX_ROWS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected roles
    |--------------------------------------------------------------------------
    |
    | Roles listed here can never be deleted from the ACL UI, guarding the
    | platform administrator role from accidental removal.
    |
    */
    'protected_roles' => ['Super Admin'],

    /*
    |--------------------------------------------------------------------------
    | Super admin account
    |--------------------------------------------------------------------------
    |
    | Consumed by SuperAdminSeeder. Override per environment through the
    | CORE_SUPER_ADMIN_* variables; the defaults are the starter-kit
    | credentials used throughout the documentation and test suite.
    |
    */
    'super_admin' => [
        'name' => env('CORE_SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('CORE_SUPER_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('CORE_SUPER_ADMIN_PASSWORD', 'password'),
    ],
];
