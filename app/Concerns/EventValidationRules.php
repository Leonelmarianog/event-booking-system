<?php

namespace App\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

trait EventValidationRules
{
    /**
     * Get the validation rules of an event: BR-E2 (capacity) and BR-E3 (start time in
     * the future).
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function eventRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'venue' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after:now'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * Get the validated attributes of the event with their types.
     *
     * @return array{title: string, description: string, venue: string, starts_at: CarbonImmutable, capacity: int}
     */
    public function eventAttributes(): array
    {
        return [
            'title' => $this->string('title')->toString(),
            'description' => $this->string('description')->toString(),
            'venue' => $this->string('venue')->toString(),
            'starts_at' => CarbonImmutable::parse($this->string('starts_at')->toString()),
            'capacity' => $this->integer('capacity'),
        ];
    }
}
