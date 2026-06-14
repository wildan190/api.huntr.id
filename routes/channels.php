<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('App.Domain.Auth.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('App.Domain.Company.Models.Company.{id}', function ($user, $id) {
    return (string) $user->company_id === (string) $id || $user->companies()->where('id', $id)->exists();
});
