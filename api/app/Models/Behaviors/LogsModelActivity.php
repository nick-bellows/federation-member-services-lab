<?php

namespace App\Models\Behaviors;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Reusable action logging behavior.
 *
 * Add this trait to any model to log create/update/delete changes
 * to the activity_log table (see App\Models\ActivityLog). Only dirty
 * fillable attributes are logged, together with their old values.
 *
 * A model may override getActivitylogOptions() for custom behavior,
 * e.g. to exclude sensitive attributes:
 *
 *     public function getActivitylogOptions(): LogOptions
 *     {
 *         return $this->defaultActivitylogOptions()->dontLogIfAttributesChangedOnly(['password']);
 *     }
 */
trait LogsModelActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return $this->defaultActivitylogOptions();
    }

    protected function defaultActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
