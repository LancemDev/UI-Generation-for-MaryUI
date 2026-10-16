<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Generation progress for one project: only its owner may listen.
Broadcast::channel('project.{projectId}', function (User $user, int $projectId) {
    return Project::whereKey($projectId)->where('user_id', $user->id)->exists();
});
