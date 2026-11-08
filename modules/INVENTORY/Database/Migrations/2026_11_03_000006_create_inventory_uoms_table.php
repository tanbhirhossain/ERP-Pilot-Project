<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_uoms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('symbol', 20);
            $table->string('code', 20)->unique();
            $table->foreignId('base_uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $table->decimal('conversion_factor', 12, 4)->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_uoms');
    }
};
