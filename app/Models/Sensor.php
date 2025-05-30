<?php

namespace App\Models;

use App\Facades\LibrenmsConfig;
use App\Models\Traits\HasThresholds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LibreNMS\Interfaces\Models\Keyable;
use LibreNMS\Util\Number;
use LibreNMS\Util\Rewrite;
use LibreNMS\Util\Time;

class Sensor extends DeviceRelatedModel implements Keyable
{
    use HasFactory;
    use HasThresholds;

    public $timestamps = false;
    protected $primaryKey = 'sensor_id';
    protected $fillable = [
        'poller_type',
        'sensor_class',
        'device_id',
        'sensor_oid',
        'sensor_index',
        'sensor_type',
        'sensor_descr',
        'sensor_divisor',
        'sensor_multiplier',
        'sensor_limit',
        'sensor_limit_warn',
        'sensor_limit_low',
        'sensor_limit_low_warn',
        'sensor_current',
        'entPhysicalIndex',
        'entPhysicalIndex_measured',
        'user_func',
        'group',
        'rrd_type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 'sensor_class' => SensorEnum::class, // TODO
        ];
    }

    // ---- Helper Methods ----

    public function classDescr(): string
    {
        return __('sensors.' . $this->sensor_class . '.short');
    }

    public function classDescrLong(): string
    {
        return __('sensors.' . $this->sensor_class . '.long');
    }

    public function unit(): string
    {
        if ($this->sensor_class == 'temperature') {
            /** @var ?User $user */
            $user = auth()->user();

            return $user && UserPref::getPref($user, 'temp_units') == 'f' ? '°F' : '°C';
        }

        return __('sensors.' . $this->sensor_class . '.unit');
    }

    public function unitLong(): string
    {
        return __('sensors.' . $this->sensor_class . '.unit_long');
    }

    public function getGraphType(): string
    {
        return 'sensor_' . $this->sensor_class;
    }

    /**
     * Format current value for user display including units.
     */
    public function formatValue($field = 'sensor_current'): string
    {
        $value = $this->$field;

        if ($value === null) {
            return $field == 'sensor_current' ? 'NaN' : '-';
        }

        if (in_array($this->rrd_type, ['COUNTER', 'DERIVE', 'DCOUNTER', 'DDERIVE'])) {
            //compute and display an approx rate for this sensor
            $value = Number::formatSi(max(0, $value - $this->sensor_prev) / LibrenmsConfig::get('rrd.step', 300), 2, 3, '');
        }

        /** @var ?User $user */
        $user = auth()->user();

        return match ($this->sensor_class) {
            'temperature' => $user && UserPref::getPref($user, 'temp_units') == 'f' ? Rewrite::celsiusToFahrenheit($value) . ' °F' : "$value °C",
            'state' => $this->currentTranslation()->state_descr ?? 'Unknown',
            'current', 'power' => Number::formatSi($value, 3, 0, $this->unit()),
            'runtime' => Time::formatInterval($value * 60),
            'power_consumed' => trim(Number::formatSi($value * 1000, 5, 5, 'Wh')),
            'dbm' => round($value, 3) . ' ' . $this->unit(),
            default => $value . ' ' . $this->unit(),
        };
    }

    public function currentTranslation(): ?StateTranslation
    {
        if ($this->sensor_class !== 'state') {
            return null;
        }

        return $this->translations->firstWhere('state_value', $this->sensor_current);
    }

    // ---- Define Relationships ----
    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\App\Models\Eventlog, $this>
     */
    public function events(): MorphMany
    {
        return $this->morphMany(Eventlog::class, 'events', 'type', 'reference');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough<\App\Models\StateIndex, \App\Models\SensorToStateIndex, $this>
     */
    public function stateIndex(): HasOneThrough
    {
        return $this->hasOneThrough(StateIndex::class, SensorToStateIndex::class, 'sensor_id', 'state_index_id', 'sensor_id', 'state_index_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\StateTranslation, $this>
     */
    public function translations(): BelongsToMany
    {
        return $this->belongsToMany(StateTranslation::class, 'sensors_to_state_indexes', 'sensor_id', 'state_index_id', 'sensor_id', 'state_index_id');
    }

    public function getCompositeKey(): string
    {
        return "$this->poller_type-$this->sensor_class-$this->device_id-$this->sensor_type-$this->sensor_index";
    }

    public function syncGroup(): string
    {
        return "$this->sensor_class-$this->poller_type";
    }

    /**
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeIsCritical($query)
    {
        return $query->whereColumn('sensor_current', '<', 'sensor_limit_low')
            ->orWhereColumn('sensor_current', '>', 'sensor_limit');
    }

    /**
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeIsDisabled($query)
    {
        return $query->where('sensor_alert', 0);
    }

    public function __toString()
    {
        $data = $this->only([
            'sensor_oid',
            'sensor_index',
            'sensor_type',
            'sensor_descr',
            'poller_type',
            'sensor_divisor',
            'sensor_multiplier',
            'entPhysicalIndex',
            'sensor_current',
        ]);
        $data[] = "(limits: LL: $this->sensor_limit_low, LW: $this->sensor_limit_low_warn, W: $this->sensor_limit_warn, H: $this->sensor_limit)";
        $data[] = "rrd_type = $this->rrd_type";

        return implode(', ', $data);
    }
}
