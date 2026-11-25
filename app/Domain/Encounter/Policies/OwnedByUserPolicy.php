<?php

namespace App\Domain\Encounter\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Owner-only access for user-owned records (scans, encounters, trips). */
class OwnedByUserPolicy
{
    public function view(User $user, Model $model): bool
    {
        return $model->getAttribute('user_id') === $user->id;
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }
}
