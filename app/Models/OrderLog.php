<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLog extends Model
{
    protected $fillable = ['order_id', 'user_id', 'action', 'message', 'meta'];

    protected $casts = ['meta' => 'json'];

    public function order() { return $this->belongsTo(Order::class); }
    public function user()  { return $this->belongsTo(User::class); }
}
