<?php

use App\Models\User;

test('BR-U4: a visitor cannot register with the email domain of deleted users', function (string $email) {
    $this->post(route('register.store'), [
        'name' => 'Mallory',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'deleted-user-1@deleted.invalid',
    'DELETED-USER-1@DELETED.INVALID',
]);

test('BR-U4: a user cannot change the email to the domain of deleted users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'deleted-user-2@deleted.invalid',
        ])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->not->toBe('deleted-user-2@deleted.invalid');
});

test('BR-U4: a user who registered first cannot block the anonymize of a later user', function () {
    $first = User::factory()->create();

    // If the registration were allowed, it would take the next ID, and the email would
    // be the placeholder of the user after it.
    $this->post(route('register.store'), [
        'name' => 'Mallory',
        'email' => 'deleted-user-'.($first->id + 2).'@deleted.invalid',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $second = User::factory()->create();
    $second->anonymize();
    $second->save();

    expect($second->fresh()->isAnonymized())->toBeTrue();
});
