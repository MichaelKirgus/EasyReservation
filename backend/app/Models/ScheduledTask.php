<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'run_at',
        'options',
        'executed',
        'executed_at',
        'active',
        'reference_type',
        'reference_id',
        'relative_to',
        'relative_offset_minutes',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'executed_at' => 'datetime',
        'options' => 'array',
        'executed' => 'boolean',
        'active' => 'boolean',
        'reference_id' => 'integer',
        'relative_offset_minutes' => 'integer',
    ];

    // Geplantes Ausführungsdatum berechnen (absolut oder relativ, auch für alle Objekte)
    public function getPlannedRunAtAttribute()
    {
        if ($this->run_at) {
            // ISO-Format mit Z für UTC
            return $this->run_at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
        }
        if ($this->reference_type && $this->relative_to && $this->relative_offset_minutes !== null) {
            // Einzelnes Objekt
            if ($this->reference_id) {
                $refs = [$this->getReferenceObject($this->reference_type, $this->reference_id)];
            } else {
                // Alle Objekte des Typs (bei Events nur zukünftige)
                $refs = $this->getAllReferenceObjects($this->reference_type, $this->relative_to);
            }
            $dates = [];
            foreach ($refs as $ref) {
                if ($ref && $ref->{$this->relative_to}) {
                    $base = strtotime($ref->{$this->relative_to});
                    if ($base !== false) {
                        $planned = $base + $this->relative_offset_minutes * 60;
                        // Nur zukünftige Termine
                        if ($planned >= time()) {
                            // ISO-Format mit Z für UTC
                            $dates[] = gmdate('Y-m-d\TH:i:s\Z', $planned);
                        }
                    }
                }
            }
            if ($dates) {
                sort($dates);
                return $dates[0];
            }
        }
        return null;
    }

    protected function getReferenceObject($type, $id) {
        if ($type === 'event') return \App\Models\Event::find($id);
        if ($type === 'reservation') return \App\Models\Reservation::find($id);
        if ($type === 'user') return \App\Models\User::find($id);
        return null;
    }

    protected function getAllReferenceObjects($type, $relativeTo = null) {
        if ($type === 'event') {
            // Nur Events mit zukünftigem relative_to-Datum
            $query = \App\Models\Event::query();
            if ($relativeTo) {
                $query->where($relativeTo, '>=', now());
            }
            return $query->get();
        }
        if ($type === 'reservation') return \App\Models\Reservation::all();
        if ($type === 'user') return \App\Models\User::all();
        return [];
    }

    protected $appends = ['planned_run_at'];
}
