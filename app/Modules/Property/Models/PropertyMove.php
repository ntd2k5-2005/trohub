<?php
namespace App\Modules\Property\Models;
use App\Support\BusinessModel;
final class PropertyMove extends BusinessModel {
    protected $table='property_moves';
    protected function casts():array{return parent::casts()+['blockers'=>'array'];}
}
