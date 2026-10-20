<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'annual_quota', 'allows_past_dates'])]
class LeaveType extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'annual_quota' => 'integer',
            'allows_past_dates' => 'boolean',
        ];
    }

    public function tracksBalance(): bool
    {
        return $this->annual_quota !== null;
    }
}
