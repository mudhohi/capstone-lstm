<?php

namespace Database\Seeders;

use App\Models\Stock;
use Illuminate\Database\Seeder;

/**
 * Hanya data master emiten (sesuai laporan: 10 Nov 2021 s.d. 6 Nov 2026,
 * 1.198 observasi per emiten). Metrik dan prediksi TIDAK di-seed: isi dari
 * hasil notebook asli lewat impor SQL dump.
 */
class StockSeeder extends Seeder
{
    public function run(): void
    {
        $stocks = [
            ['BBCA', 'PT Bank Central Asia Tbk'],
            ['BBRI', 'PT Bank Rakyat Indonesia (Persero) Tbk'],
            ['BMRI', 'PT Bank Mandiri (Persero) Tbk'],
            ['BBNI', 'PT Bank Negara Indonesia (Persero) Tbk'],
        ];

        foreach ($stocks as [$ticker, $name]) {
            Stock::updateOrCreate(['ticker' => $ticker], [
                'name' => $name,
                'data_start_date' => '2021-11-10',
                'data_end_date' => '2026-11-06',
                'total_observations' => 1198,
            ]);
        }
    }
}
