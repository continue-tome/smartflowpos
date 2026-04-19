<?php

namespace App\Observers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditObserver
{
    private array $excluded = ['password', 'pin', 'remember_token', 'updated_at'];

    public function created(Model $model): void  { $this->log('created', $model, [], $model->toArray()); }
    public function updated(Model $model): void  { $this->log('updated', $model, $model->getOriginal(), $model->getChanges()); }
    public function deleted(Model $model): void  { $this->log('deleted', $model, $model->toArray(), []); }
    public function restored(Model $model): void { $this->log('restored', $model, [], []); }

    private function log(string $action, Model $model, array $old, array $new): void
    {
        $old = array_diff_key($old, array_flip($this->excluded));
        $new = array_diff_key($new, array_flip($this->excluded));

        ActivityLog::create([
            'restaurant_id' => $model->restaurant_id ?? Auth::user()?->restaurant_id,
            'user_id'       => Auth::id(),
            'action'        => $action,
            'module'        => $this->getModule($model),
            'subject_type'  => get_class($model),
            'subject_id'    => $model->getKey(),
            'description'   => class_basename($model) . " #{$model->getKey()} — {$action}",
            'old_values'    => empty($old) ? null : $old,
            'new_values'    => empty($new) ? null : $new,
            'ip_address'    => Request::ip(),
        ]);
    }

    private function getModule(Model $model): string
    {
        return match(true) {
            $model instanceof \App\Models\Order        => 'order',
            $model instanceof \App\Models\OrderItem    => 'order_item',
            $model instanceof \App\Models\Payment      => 'payment',
            $model instanceof \App\Models\User         => 'user',
            $model instanceof \App\Models\Ingredient   => 'stock',
            $model instanceof \App\Models\Delivery     => 'delivery',
            $model instanceof \App\Models\Cancellation => 'cancellation',
            $model instanceof \App\Models\Product      => 'product',
            default                                    => 'system',
        };
    }
}
