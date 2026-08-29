<?php

namespace App\Policies;

use App\Models\ContentProject;
use App\Models\User;

/**
 * Everything in the Content Studio is strictly owner-scoped. Controllers pair
 * these with a 404 rather than a 403 so a foreign project is indistinguishable
 * from one that does not exist.
 */
class ContentProjectPolicy
{
    public function view(User $user, ContentProject $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, ContentProject $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function delete(User $user, ContentProject $project): bool
    {
        return $project->user_id === $user->id;
    }
}
