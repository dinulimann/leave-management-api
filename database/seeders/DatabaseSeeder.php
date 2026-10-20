<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo data. Every account uses the password "password".
     */
    public function run(): void
    {
        LeaveType::insert([
            ['code' => 'ANNUAL', 'name' => 'Annual leave', 'annual_quota' => 12, 'allows_past_dates' => false],
            ['code' => 'SICK', 'name' => 'Sick leave', 'annual_quota' => 14, 'allows_past_dates' => true],
            ['code' => 'UNPAID', 'name' => 'Unpaid leave', 'annual_quota' => null, 'allows_past_dates' => false],
        ]);

        // Fixed-date holidays only, as an example. Load the official national
        // calendar (including moveable feasts) before using this for real.
        $year = now()->year;
        foreach ([
            ["$year-01-01", "New Year's Day"],
            ["$year-05-01", 'Labour Day'],
            ["$year-06-01", 'Pancasila Day'],
            ["$year-08-17", 'Independence Day'],
            ["$year-12-25", 'Christmas Day'],
        ] as [$date, $name]) {
            Holiday::create(['date' => $date, 'name' => $name]);
        }

        $manager = User::factory()->create([
            'name' => 'Maya Manager', 'email' => 'manager@example.com', 'role' => 'manager',
        ]);
        User::factory()->create([
            'name' => 'Eka Employee', 'email' => 'employee@example.com', 'manager_id' => $manager->id,
        ]);
        User::factory()->create([
            'name' => 'Budi Employee', 'email' => 'budi@example.com', 'manager_id' => $manager->id,
        ]);
    }
}
