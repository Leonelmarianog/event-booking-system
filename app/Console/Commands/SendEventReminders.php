<?php

namespace App\Console\Commands;

use App\Actions\SendEventReminders\SendEventReminders as SendEventRemindersAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('events:send-reminders')]
#[Description('Send the reminder emails for the events that start in the next 24 hours')]
class SendEventReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SendEventRemindersAction $sendEventReminders): int
    {
        $count = $sendEventReminders->handle();

        $this->info("Reminders sent for {$count} ".Str::plural('event', $count).'.');

        return self::SUCCESS;
    }
}
