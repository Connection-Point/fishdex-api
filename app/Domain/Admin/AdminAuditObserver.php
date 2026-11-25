<?php

namespace App\Domain\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes every admin-made change to catalogue/rarity/settings/review data to
 * admin_activity_log (who, what, before → after). App-user changes are not logged here.
 */
class AdminAuditObserver
{
    private const HIDDEN = ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'];

    public function created(Model $model): void
    {
        $this->log('created', $model, ['after' => $this->clean($model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());
        unset($changes['updated_at']);
        if ($changes) {
            $this->log('updated', $model, [
                'before' => array_intersect_key($this->clean($model->getOriginal()), $changes),
                'after' => $changes,
            ]);
        }
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, ['before' => $this->clean($model->getOriginal())]);
    }

    private function log(string $action, Model $model, array $changes): void
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return;
        }

        DB::table('admin_activity_log')->insert([
            'admin_id' => $admin->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => class_basename($model),
            'subject_id' => is_numeric($model->getKey()) ? $model->getKey() : null,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'ip_address' => request()?->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function clean(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::HIDDEN));
    }
}
