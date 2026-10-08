<?php

namespace App\Actions\DeleteAccount;

use App\Enums\EventStatus;
use App\Exceptions\Domain\AccountCannotBeDeleted;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAccount
{
    /**
     * Delete the account of the user: check the rules (BR-U1, BR-U2), delete the drafts
     * (BR-U3), the password reset token and the passkeys, and anonymize the user row
     * (BR-U4). The Action locks the user row first, the same as ReserveSeats,
     * CreateEvent and PublishEvent, so a booking or a publish cannot slip in.
     *
     * @throws AccountCannotBeDeleted
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $user->ensureCanBeDeleted();

            $user->organizedEvents()->where('status', EventStatus::Draft)->delete();

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->passkeys()->delete();

            $user->anonymize();
            $user->save();
        });
    }
}
