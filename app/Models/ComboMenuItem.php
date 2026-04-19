<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComboMenuItem extends Model
{
    protected $fillable = ['combo_menu_id', 'product_id', 'quantity'];

    public function comboMenu() { return $this->belongsTo(ComboMenu::class); }
    public function product()   { return $this->belongsTo(Product::class); }
}
