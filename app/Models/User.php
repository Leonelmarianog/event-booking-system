<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Exceptions\Domain\AccountCannotBeDeleted;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property CarbonImmutable|null $anonymized_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Booking> $bookings
 * @property-read Collection<int, Event> $organizedEvents
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * The bookings that the user made, in all statuses.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * The events that the user organizes, in all statuses.
     *
     * @return HasMany<Event, $this>
     */
    public function organizedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    /**
     * Check that the user can delete the account: the user organizes no published event
     * that has not started (BR-U1), and has no confirmed booking for an event that has
     * not started (BR-U2).
     *
     * @throws AccountCannotBeDeleted
     */
    public function ensureCanBeDeleted(): void
    {
        if ($this->organizedEvents()->published()->upcoming()->exists()) {
            throw AccountCannotBeDeleted::organizesUpcomingEvent();
        }

        $holdsUpcomingBooking = $this->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->whereHas('event', fn (Builder $query) => $query->upcoming())
            ->exists();

        if ($holdsUpcomingBooking) {
            throw AccountCannotBeDeleted::holdsUpcomingBooking();
        }
    }

    /**
     * Replace the personal data of the user and remove the ways to log in (BR-U4). The
     * row stays, so that events and bookings keep their history. Saves nothing.
     */
    public function anonymize(): void
    {
        $this->name = 'Deleted user';
        $this->email = "deleted-user-{$this->id}@deleted.invalid";
        $this->password = null;
        $this->remember_token = null;
        $this->two_factor_secret = null;
        $this->two_factor_recovery_codes = null;
        $this->two_factor_confirmed_at = null;
        $this->anonymized_at = now();
    }

    /**
     * Whether the user deleted the account.
     */
    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }
}
