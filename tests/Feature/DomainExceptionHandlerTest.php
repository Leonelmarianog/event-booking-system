<?php

use App\Exceptions\Domain\DomainException;
use Illuminate\Support\Facades\Route;

/**
 * A domain exception for the tests, with or without a form field.
 */
class TestDomainException extends DomainException
{
    public function __construct(private ?string $formField = null)
    {
        parent::__construct('The rule does not allow this.');
    }

    public function field(): ?string
    {
        return $this->formField;
    }
}

beforeEach(function () {
    Route::middleware('web')->post('/test/domain-exception', function () {
        throw new TestDomainException(request()->string('field')->toString() ?: null);
    });
});

test('an Inertia request goes back with an error toast', function () {
    $this->from('/test/form')
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/test/domain-exception')
        ->assertRedirect('/test/form')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rule does not allow this.']);
});

test('an Inertia request also gets the field error when the exception names a field', function () {
    $this->from('/test/form')
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/test/domain-exception', ['field' => 'capacity'])
        ->assertRedirect('/test/form')
        ->assertSessionHasErrors(['capacity' => 'The rule does not allow this.'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rule does not allow this.']);
});

test('a JSON request gets 422 with the message', function () {
    $this->postJson('/test/domain-exception')
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'The rule does not allow this.']);
});
