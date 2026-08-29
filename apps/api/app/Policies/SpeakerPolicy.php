<?php

namespace App\Policies;

use App\Models\Speaker;
use App\Models\User;

class SpeakerPolicy
{
    public function view(User $user, Speaker $speaker): bool
    {
        return $speaker->user_id === $user->id;
    }

    public function update(User $user, Speaker $speaker): bool
    {
        return $speaker->user_id === $user->id;
    }

    public function delete(User $user, Speaker $speaker): bool
    {
        return $speaker->user_id === $user->id;
    }
}
