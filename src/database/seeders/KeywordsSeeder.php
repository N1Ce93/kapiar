<?php

namespace Database\Seeders;

use App\Models\Keyword;
use Illuminate\Database\Seeder;

class KeywordsSeeder extends Seeder
{
    public function run(): void
    {
        $keywords = [
            'Запорізька обласна клінічна лікарня',
            'Запорізькій обласній клінічній лікарні',
            'Запорізької обласної клінічної лікарні',
            'Запорожская областная больница',
            'ЗОКБ',
            'ЗОКЛ',
            'Игорь Шишка',
            'Ігор Шишка',
            'обласна клінічна лікарня',
            'обласна лікарня',
        ];

        foreach ($keywords as $phrase) {
            Keyword::updateOrCreate(
                ['phrase' => $phrase],
                ['enabled' => true],
            );
        }
    }
}
