<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MenuSection;

class MenuSectionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Only add if not exists
        if (MenuSection::count() == 0) {
            MenuSection::insert([
                [
                    'name'          => 'Support Section',
                    'created_at'       => now(),
                    'updated_at'       => now()
                ],
                [
                    'name'          => 'Legal Section',
                    'created_at'       => now(),
                    'updated_at'       => now()
                ],
                [
                    'name'          => 'Help Section',
                    'created_at'       => now(),
                    'updated_at'       => now()
                ],
            ]);
        } else {
            // Add Help Section if it doesn't exist (ID 3)
            if (!MenuSection::find(3)) {
                MenuSection::create([
                    'id'            => 3,
                    'name'          => 'Help Section',
                    'created_at'    => now(),
                    'updated_at'    => now()
                ]);
            }
        }
    }
}
