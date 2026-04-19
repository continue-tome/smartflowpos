<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerTabOrder extends Model
{
    protected $fillable = ['customer_tab_id', 'order_id'];

    public function customerTab() { return $this->belongsTo(CustomerTab::class); }
    public function order()       { return $this->belongsTo(Order::class); }
}
