<?php

namespace Database\Seeders;

use App\Models\Floor;
use App\Models\Table;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class FloorAndTableSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Restaurant::first();
        if (!$restaurant) {
            $this->command->error('Aucun restaurant trouvé.');
            return;
        }

        // Création de l'étage unique
        $floor = Floor::firstOrCreate([
            'restaurant_id' => $restaurant->id,
            'name' => 'SALLE PRINCIPALE'
        ]);

        $this->command->info('Création de 100 tables dans la SALLE PRINCIPALE...');

        // Création des 100 tables
        for ($i = 1; $i <= 100; $i++) {
            Table::firstOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'floor_id'      => $floor->id,
                    'number'        => (string)$i
                ],
                [
                    'capacity' => 4,
                    'status'   => 'free'
                ]
            );
        }

        $this->command->info('Succès : 100 tables ont été créées.');
    }
}
