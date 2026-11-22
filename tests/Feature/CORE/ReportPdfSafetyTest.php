<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\CORE\Http\Controllers\ReportPdfController;
use Modules\CORE\Models\User;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('report export is unavailable to guests', function () {
    $this->post(route('core.reports.pdf'), ['title' => 'Test', 'headings' => ['Item']])->assertRedirect();
});

test('an authenticated user may download an explicitly unverified view export without an optional permission', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->post(route('core.reports.pdf'), ['title' => 'View export', 'headings' => ['Item'], 'rows' => [['Example']]]);
    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
});

test('a supplied permission is checked', function () {
    Permission::findOrCreate('accounts.reports.view', 'web');
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('core.reports.pdf'), ['title' => 'View export', 'permission' => 'accounts.reports.view', 'headings' => ['Item']])->assertForbidden();
});

test('client data is escaped and the document discloses it is not verified', function () {
    $method = new ReflectionMethod(ReportPdfController::class, 'pdfHtml');
    $html = $method->invoke(app(ReportPdfController::class), 'Test', '', [], ['Item'], new Collection([['<script>alert(1)</script>']]), false, 2000, '', 'Tester');
    expect($html)->toContain('UNVERIFIED CLIENT-SUPPLIED DATA')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});
