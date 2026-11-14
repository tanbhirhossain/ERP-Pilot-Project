<?php

/**
 * Phase 2 — F19 attachments + U16 document PDFs.
 *
 * Attachments are allow-listed by type, gated on view (download) / manage
 * (upload, delete) permissions, and stored on the private disk. Document PDFs are
 * gated on the document's own view permission. Unknown types 404.
 *
 * Helper names are prefixed `p2ad*`.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\CORE\Models\Attachment;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\Invoice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function p2adUser(array $permissions, int $salt): User
{
    $role = Role::create(['name' => "P2AD Role {$salt}", 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
    }

    $user = User::create([
        'name' => "P2AD User {$salt}", 'email' => "p2ad{$salt}@example.test",
        'password' => bcrypt('secret-password'), 'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function p2adInvoice(): Invoice
{
    $customer = Partner::create(['partner_code' => 'P2AD-'.uniqid(), 'type' => 'customer', 'name' => 'P2AD Customer', 'is_active' => true, 'currency' => 'BDT']);
    $warehouse = Warehouse::factory()->create();

    $invoice = new Invoice;
    $invoice->forceFill([
        'invoice_no' => 'P2AD-INV-'.uniqid(), 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
        'invoice_date' => now()->toDateString(), 'status' => 'posted',
        'subtotal' => 1000, 'discount_amount' => 0, 'vat_amount' => 150, 'total_amount' => 1150, 'paid_amount' => 0,
    ])->save();

    return $invoice;
}

test('a manager can upload an attachment to an invoice', function () {
    Storage::fake('local');
    $user = p2adUser(['sales.invoices.manage'], 1);
    $invoice = p2adInvoice();

    $this->actingAs($user)->post(route('core.attachments.store', ['type' => 'invoice', 'id' => $invoice->id]), [
        'file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $attachment = Attachment::where('attachable_type', Invoice::class)->where('attachable_id', $invoice->id)->first();
    expect($attachment)->not->toBeNull()->and($attachment->name)->toBe('scan.pdf');
    Storage::disk('local')->assertExists($attachment->path);
});

test('uploading requires the manage permission', function () {
    Storage::fake('local');
    $viewer = p2adUser(['sales.invoices.view'], 2);
    $invoice = p2adInvoice();

    $this->actingAs($viewer)->post(route('core.attachments.store', ['type' => 'invoice', 'id' => $invoice->id]), [
        'file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
    ])->assertForbidden();

    expect(Attachment::count())->toBe(0);
});

test('download requires the view permission and delete removes the file', function () {
    Storage::fake('local');
    $manager = p2adUser(['sales.invoices.manage', 'sales.invoices.view'], 3);
    $invoice = p2adInvoice();

    $this->actingAs($manager)->post(route('core.attachments.store', ['type' => 'invoice', 'id' => $invoice->id]), [
        'file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
    ]);
    $attachment = Attachment::first();

    $nobody = p2adUser([], 4);
    $this->actingAs($nobody)->get(route('core.attachments.download', $attachment))->assertForbidden();

    $this->actingAs($manager)->get(route('core.attachments.download', $attachment))->assertOk();

    $path = $attachment->path;
    $this->actingAs($manager)->delete(route('core.attachments.destroy', $attachment))->assertRedirect();
    expect(Attachment::find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

test('an unknown attachment type is rejected', function () {
    Storage::fake('local');
    $user = p2adUser(['sales.invoices.manage'], 5);

    $this->actingAs($user)->post(route('core.attachments.store', ['type' => 'evil', 'id' => 1]), [
        'file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
    ])->assertNotFound();
});

test('the invoice document PDF renders for an authorised viewer', function () {
    $viewer = p2adUser(['sales.invoices.view'], 6);
    $invoice = p2adInvoice();

    $response = $this->actingAs($viewer)->get(route('core.documents.pdf', ['type' => 'invoice', 'id' => $invoice->id]));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('the document PDF requires the document view permission', function () {
    $nobody = p2adUser([], 7);
    $invoice = p2adInvoice();

    $this->actingAs($nobody)->get(route('core.documents.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertForbidden();
});

test('an unknown document type is rejected', function () {
    $user = p2adUser(['sales.invoices.view'], 8);

    $this->actingAs($user)->get(route('core.documents.pdf', ['type' => 'evil', 'id' => 1]))->assertNotFound();
});
