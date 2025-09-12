<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Zap\Enums\ScheduleTypes;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->morphs('schedulable'); // User, Resource, etc.
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_recurring')->default(false);
            $table->uuid('recurring_id')->nullable()->index();
            $table->string('frequency')->nullable(); // daily, weekly, monthly
            $table->json('frequency_config')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);

            $table->enum('schedule_type', ScheduleTypes::values())
                ->default(ScheduleTypes::CUSTOM)
                ->after('description');

            $table->timestamps();

            // Indexes for performance
            $table->index(['schedulable_type', 'schedulable_id'], 'schedules_schedulable_index');
            $table->index(['start_date', 'end_date'], 'schedules_date_range_index');
            $table->index(['start_time', 'end_time'], 'schedule_time_range_index');
            $table->index('is_active', 'schedules_is_active_index');
            $table->index('is_recurring', 'schedules_is_recurring_index');

            $table->index(['schedulable_type', 'schedulable_id', 'schedule_type'], 'schedules_schedulable_type_index');
            $table->index('frequency', 'schedules_frequency_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
