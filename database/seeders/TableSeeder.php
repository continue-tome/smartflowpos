<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        // Les tables sont désormais initialisées automatiquement par le RestaurantSeeder
        // pour garantir la cohérence des données.
        $this->call(RestaurantSeeder::class);
    }
}
