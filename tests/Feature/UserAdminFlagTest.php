<?php

use App\Models\User;

test('a new user is not an admin', function () {
    $user = User::factory()->create();

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('the admin factory state makes an admin', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->fresh()->is_admin)->toBeTrue();
});

test('is_admin is not mass assignable', function () {
    $user = User::create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
});
