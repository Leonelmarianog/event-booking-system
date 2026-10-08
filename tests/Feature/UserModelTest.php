<?php

use App\Models\User;

test('BR-U4: anonymize replaces the personal data and keeps the row', function () {
    $this->freezeSecond();
    $user = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace']);

    $user->anonymize();

    expect($user->name)->toBe('Deleted user')
        ->and($user->email)->toBe("deleted-user-{$user->id}@deleted.invalid")
        ->and($user->password)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->anonymized_at->equalTo(now()))->toBeTrue()
        ->and($user->isAnonymized())->toBeTrue()
        ->and($user->isDirty())->toBeTrue();
});

test('BR-U4: the anonymized user is stored with a null password', function () {
    $user = User::factory()->create();

    $user->anonymize();
    $user->save();

    $stored = $user->fresh();

    expect($stored)->not->toBeNull()
        ->and($stored->password)->toBeNull()
        ->and($stored->anonymized_at)->not->toBeNull()
        ->and($stored->isAnonymized())->toBeTrue();
});

test('BR-U4: two anonymized users get different emails', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $first->anonymize();
    $first->save();
    $second->anonymize();
    $second->save();

    expect($first->fresh()->email)->not->toBe($second->fresh()->email);
});

test('a new user is not anonymized', function () {
    $user = User::factory()->create();

    expect($user->fresh()->anonymized_at)->toBeNull()
        ->and($user->isAnonymized())->toBeFalse();
});

test('the anonymized factory state makes an anonymized user', function () {
    $user = User::factory()->anonymized()->create();

    expect($user->fresh()->isAnonymized())->toBeTrue()
        ->and($user->fresh()->name)->toBe('Deleted user')
        ->and($user->fresh()->password)->toBeNull();
});
