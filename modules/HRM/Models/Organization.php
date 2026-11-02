<?php
namespace Modules\HRM\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Organization extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = ['created_at'=>'datetime','updated_at'=>'datetime'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\OrganizationFactory::new(); }
}
