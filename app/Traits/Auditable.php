<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    public static function bootedAuditable(): void
    {
        static::created(fn($model) => static::audit('created', $model, [], $model->getAttributes()));
        static::updated(fn($model) => static::audit('updated', $model, $model->getOriginal(), $model->getChanges()));
        static::deleted(fn($model) => static::audit('deleted', $model, $model->getAttributes(), []));
    }

    protected static function audit(string $action, $model, array $old, array $new): void
    {
        // Exclure les champs sensibles
        $excluded = ['password', 'pin', 'remember_token', 'updated_at'];
        $old = array_diff_key($old, array_flip($excluded));
        $new = array_diff_key($new, array_flip($excluded));

        ActivityLog::create([
            'restaurant_id' => $model->restaurant_id ?? Auth::user()?->restaurant_id,
            'user_id'       => Auth::id(),
            'action'        => $action,
            'module'        => static::getAuditModule(),
            'subject_type'  => get_class($model),
            'subject_id'    => $model->getKey(),
            'description'   => static::buildDescription($action, $model),
            'old_values'    => empty($old) ? null : $old,
            'new_values'    => empty($new) ? null : $new,
            'ip_address'    => Request::ip(),
            'user_agent'    => Request::userAgent(),
        ]);
    }

    protected static function getAuditModule(): string
    {
        $const = static::class . '::AUDIT_MODULE';

        return defined($const) ? constant($const) : 'system';
    }

    protected static function buildDescription(string $action, $model): string
    {
        $className = class_basename($model);
        $id = $model->getKey();
        return match($action) {
            'created' => "{$className} #{$id} créé",
            'updated' => "{$className} #{$id} modifié",
            'deleted' => "{$className} #{$id} supprimé",
            default   => "{$className} #{$id} — {$action}",
        };
    }

    // Méthode manuelle pour logs custom
    public function logActivity(string $action, string $description, array $meta = []): void
    {
        ActivityLog::create([
            'restaurant_id' => $this->restaurant_id ?? Auth::user()?->restaurant_id,
            'user_id'       => Auth::id(),
            'action'        => $action,
            'module'        => static::getAuditModule(),
            'subject_type'  => get_class($this),
            'subject_id'    => $this->getKey(),
            'description'   => $description,
            'new_values'    => empty($meta) ? null : $meta,
            'ip_address'    => Request::ip(),
        ]);
    }
}
