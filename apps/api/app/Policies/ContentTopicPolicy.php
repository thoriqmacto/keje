<?php

namespace App\Policies;

use App\Models\ContentTopic;
use App\Models\User;

class ContentTopicPolicy
{
    public function view(User $user, ContentTopic $topic): bool
    {
        return $topic->user_id === $user->id;
    }

    public function update(User $user, ContentTopic $topic): bool
    {
        return $topic->user_id === $user->id;
    }

    public function delete(User $user, ContentTopic $topic): bool
    {
        return $topic->user_id === $user->id;
    }
}
