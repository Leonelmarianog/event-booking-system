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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->smallInteger('quantity');
            $table->string('status')->default('confirmed');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
            $table->index(['event_id', 'status']);
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_quantity_check CHECK (quantity BETWEEN 1 AND 4)');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('confirmed', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX bookings_event_id_user_id_confirmed_unique ON bookings (event_id, user_id) WHERE status = 'confirmed'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
