<?php

use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('org', function (User $user) {
    return ChannelAccess::canAccessOrg($user);
});

Broadcast::channel('location.{id}', function (User $user, int $id) {
    return ChannelAccess::canAccessLocation($user, (int) $id);
});
