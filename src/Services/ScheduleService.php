<?php

namespace Zap\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Zap\Builders\ScheduleBuilder;
use Zap\Exceptions\ScheduleConflictException;
use Zap\Models\Schedule;

class ScheduleService
{
    public function __construct(
        private ValidationService $validator,
        private ConflictDetectionService $conflictService
    ) {}

    /**
     * Create a new schedule with validation and conflict detection.
     *
     * @return Collection<int, Schedule>
     */
    public function create(
        Model $schedulable,
        array $attributes,
        array $periods = [],
        array $rules = []
    ): Collection {
        return DB::transaction(function () use ($schedulable, $attributes, $periods, $rules) {
            // Set default values
            $attributes = array_merge([
                'is_active' => true,
                'is_recurring' => false,
            ], $attributes);

            // Validate the schedule data
            $this->validator->validate($schedulable, $attributes, $periods, $rules);

            $startDate = \Carbon\Carbon::parse($attributes['start_date']);

            if ($attributes['is_recurring']) {
                // fix the start_date to the first occurrence based on frequency
                if (!$this->isCorrectDate(
                    $attributes['frequency'],
                    $attributes['frequency_config'],
                    $startDate
                )) {
                    $startDate = $this->getNextDate(
                        $attributes['frequency'],
                        $attributes['frequency_config'],
                        $startDate
                    );
                    $attributes['frequency_config']['start_date'] = $attributes['start_date'];
                    $attributes['frequency_config']['end_date'] = $attributes['end_date'];
                }
            }

            $current = $startDate->copy();
            $end = $attributes['is_recurring'] ? \Carbon\Carbon::parse($attributes['end_date']) : $current->copy();

            // Create the schedules
            $schedules = collect([]);
            $recurringIds = [];
            do {
                foreach ($periods as $k => $period) {
                    $data = array_merge($attributes, [
                        'start_date' => $current->toDateString(),
                        'end_date' => null,
                        'start_time' => $period['start_time'],
                        'end_time' => $period['end_time'],
                        'recurring_id' => $recurringIds[$k] ?? null,
                    ]);

                    if (isset($recurringIds[$k])) {
                        $data['frequency'] = null;
                        $data['frequency_config'] = null;
                    }

                    $schedule = new Schedule($data);
                    $schedule->schedulable_type = $schedulable->getMorphClass();
                    $schedule->schedulable_id = $schedulable->getKey();
                    $schedule->save();

                    if (!isset($recurringIds[$k])) {
                        $recurringIds[$k] = $schedule->getKey();
                    }

                    $schedules->push($schedule);
                }

                if (!$attributes['is_recurring']) {
                    break;
                }

                $current = $this->getNextDate(
                    $attributes['frequency'],
                    $attributes['frequency_config'],
                    $current
                );
            } while ($current->lessThanOrEqualTo($end));

            // Note: Conflict checking is now done during validation phase
            // No need to check again after creation

            // Fire the created event
            // Event::dispatch(new ScheduleCreated($schedule));

            return $schedules;
        });
    }

    private function isCorrectDate($frequency, $config, \Carbon\Carbon $date): bool
    {
        switch ($frequency) {
            case 'daily':
                return true;

            case 'weekly':
                $allowedDays = $config['days'] ?? [];

                return empty($allowedDays) || in_array(strtolower($date->format('l')), $allowedDays);

            case 'monthly':
                $dayOfMonth = $config['day_of_month'] ?? $date->day;

                return $date->day === $dayOfMonth;

            default:
                return false;
        }
    }

    private function getNextDate($frequency, $config, \Carbon\Carbon $current): \Carbon\Carbon
    {
        $current = $current->copy();

        switch ($frequency) {
            case 'daily':
                return $current->addDay();

            case 'weekly':
                $allowedDays = $config['days'] ?? [];
                do {
                    $current->addDay();
                } while (! empty($allowedDays) && ! in_array(strtolower($current->format('l')), $allowedDays));

                return $current;

            case 'monthly':
                $dayOfMonth = $config['day_of_month'] ?? $current->day;
                do {
                    $current->addDay();
                } while ($current->day !== $dayOfMonth);

                return $current;

            default:
                return $current->addDay();
        }
    }

    /**
     * Update an existing schedule.
     */
    public function update(Schedule $schedule, array $attributes, array $periods = []): Schedule
    {
        return DB::transaction(function () use ($schedule, $attributes, $periods) {
            // Update the schedule attributes
            $schedule->update($attributes);

            // Update periods if provided
            if (! empty($periods)) {
                // Delete existing periods and create new ones
                $schedule->periods()->delete();

                foreach ($periods as $period) {
                    $period['schedule_id'] = $schedule->id;
                    $schedule->periods()->create($period);
                }
            }

            // Check for conflicts after update
            $conflicts = $this->conflictService->findConflicts($schedule);
            if (! empty($conflicts)) {
                throw (new ScheduleConflictException(
                    'Updated schedule conflicts with existing schedules'
                ))->setConflictingSchedules($conflicts);
            }

            return $schedule->fresh('periods');
        });
    }

    /**
     * Delete a schedule.
     */
    public function delete(Schedule $schedule): bool
    {
        return DB::transaction(function () use ($schedule) {
            // Delete all periods first
            $schedule->periods()->delete();

            // Delete the schedule
            return $schedule->delete();
        });
    }

    /**
     * Create a schedule builder for a schedulable model.
     */
    public function for(Model $schedulable): ScheduleBuilder
    {
        return (new ScheduleBuilder)->for($schedulable);
    }

    /**
     * Create a new schedule builder.
     */
    public function schedule(): ScheduleBuilder
    {
        return new ScheduleBuilder;
    }

    /**
     * Find all schedules that conflict with the given schedule.
     */
    public function findConflicts(Schedule $schedule): array
    {
        return $this->conflictService->findConflicts($schedule);
    }

    /**
     * Check if a schedule has conflicts.
     */
    public function hasConflicts(Schedule $schedule): bool
    {
        return $this->conflictService->hasConflicts($schedule);
    }

    /**
     * Get available time slots for a schedulable on a given date.
     */
    public function getAvailableSlots(
        Model $schedulable,
        string $date,
        string $startTime = '09:00',
        string $endTime = '17:00',
        int $slotDuration = 60
    ): array {
        if (method_exists($schedulable, 'getAvailableSlots')) {
            return $schedulable->getAvailableSlots($date, $startTime, $endTime, $slotDuration);
        }

        return [];
    }

    /**
     * Check if a schedulable is available at a specific time.
     */
    public function isAvailable(
        Model $schedulable,
        string $date,
        string $startTime,
        string $endTime
    ): bool {
        if (method_exists($schedulable, 'isAvailableAt')) {
            return $schedulable->isAvailableAt($date, $startTime, $endTime);
        }

        return true; // Default to available if no schedule trait
    }

    /**
     * Get all schedules for a schedulable within a date range.
     */
    public function getSchedulesForDateRange(
        Model $schedulable,
        string $startDate,
        string $endDate
    ): \Illuminate\Database\Eloquent\Collection {
        if (method_exists($schedulable, 'schedulesForDateRange')) {
            return $schedulable->schedulesForDateRange($startDate, $endDate)->get();
        }

        return new \Illuminate\Database\Eloquent\Collection;
    }

    /**
     * Generate recurring schedule instances for a given period.
     */
    public function generateRecurringInstances(
        Schedule $schedule,
        string $startDate,
        string $endDate
    ): array {
        if (! $schedule->is_recurring) {
            return [];
        }

        $instances = [];
        $current = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);

        while ($current->lte($end)) {
            if ($this->shouldCreateInstance($schedule, $current)) {
                $instances[] = [
                    'date' => $current->toDateString(),
                    'schedule' => $schedule,
                ];
            }

            $current = $this->getNextRecurrence($schedule, $current);
        }

        return $instances;
    }

    /**
     * Check if a recurring instance should be created for the given date.
     */
    private function shouldCreateInstance(Schedule $schedule, \Carbon\Carbon $date): bool
    {
        $frequency = $schedule->frequency;
        $config = $schedule->frequency_config ?? [];

        switch ($frequency) {
            case 'daily':
                return true;

            case 'weekly':
                $allowedDays = $config['days'] ?? [];

                return empty($allowedDays) || in_array(strtolower($date->format('l')), $allowedDays);

            case 'monthly':
                $dayOfMonth = $config['day_of_month'] ?? $date->day;

                return $date->day === $dayOfMonth;

            default:
                return false;
        }
    }

    /**
     * Get the next recurrence date.
     */
    private function getNextRecurrence(Schedule $schedule, \Carbon\Carbon $current): \Carbon\Carbon
    {
        $frequency = $schedule->frequency;

        switch ($frequency) {
            case 'daily':
                return $current->addDay();

            case 'weekly':
                return $current->addWeek();

            case 'monthly':
                return $current->addMonth();

            default:
                return $current->addDay();
        }
    }
}
