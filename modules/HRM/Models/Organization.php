<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\HRM\Database\Factories\OrganizationFactory;

class Organization extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];

    protected static function newFactory()
    {
        return OrganizationFactory::new();
    }
}
