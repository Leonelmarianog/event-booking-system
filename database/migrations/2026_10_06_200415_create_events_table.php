<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('venue');
            $table->timestampTz('starts_at');
            $table->integer('capacity');
            $table->integer('seats_available');
            $table->string('status')->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'starts_at']);
            $table->index('organizer_id');
        });

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_capacity_check CHECK (capacity > 0)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_seats_available_check CHECK (seats_available >= 0 AND seats_available <= capacity)');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft', 'published', 'cancelled'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
