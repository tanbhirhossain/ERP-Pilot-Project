<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;

/**
 * Export regression coverage.
 *
 * Both exports broke on PHP 8.2 once the dependency set drifted onto
 * PhpSpreadsheet 5 / maatwebsite-excel 4 (PHP 8.3-only packages). The lock is
 * now pinned to a PHP 8.2 floor, and these assertions cover all four
 * resources in both formats so a future bump that breaks exports fails here.
 */
function coreAdmin(): User
{
    return User::query()
        ->where('email', (string) config('core.super_admin.email', 'admin@example.com'))
        ->firstOrFail();
}

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
    User::factory()->count(5)->create();
});

test('excel export downloads a workbook', function (string $resource) {
    $response = $this->actingAs(coreAdmin())
        ->get(route('core.exports', ['resource' => $resource, 'format' => 'excel']));

    $response->assertOk()->assertHeader(
        'content-type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );

    // The service stamps the filename with the generation time, so match the
    // stable parts: an attachment named after the resource, ending in .xlsx.
    expect($response->headers->get('content-disposition'))
        ->toStartWith('attachment;')
        ->toContain("core-{$resource}")
        ->toEndWith('.xlsx');
})->with(['users', 'roles', 'permissions', 'activities']);

test('pdf export downloads a document', function (string $resource) {
    $response = $this->actingAs(coreAdmin())
        ->get(route('core.exports', ['resource' => $resource, 'format' => 'pdf']));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))
        ->toStartWith('attachment;')
        ->toContain("core-{$resource}")
        ->toEndWith('.pdf');
})->with(['users', 'roles', 'permissions', 'activities']);

test('exports honour the bracketed filter query string the DataTable sends', function () {
    $url = route('core.exports', ['resource' => 'users', 'format' => 'excel'])
        .'?search=&filters%5Bverified%5D=1&sort=name&dir=asc&per_page=25';

    $this->actingAs(coreAdmin())
        ->get($url)
        ->assertOk()
        ->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
});

test('an unsupported resource or format is not found', function (string $url) {
    $this->actingAs(coreAdmin())->get($url)->assertNotFound();
})->with([
    'unknown resource' => '/core/exports/invoices/excel',
    'unknown format' => '/core/exports/users/docx',
]);

test('a user without the view permission cannot export', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('core.exports', ['resource' => 'users', 'format' => 'excel']))
        ->assertForbidden();
});

test('guests are redirected away from exports', function () {
    $this->get(route('core.exports', ['resource' => 'users', 'format' => 'excel']))
        ->assertRedirect('/login');
});
