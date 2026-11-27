<?php

namespace App\Services;

/**
 * Mencari metrik evaluasi di dalam metadata_*.json. Skema metadata tidak baku, jadi pencocokan memakai
 * akhiran nama kunci (JSON bersarang diratakan): wfv_mae, walk_forward_mae, mae_walk_forward, validation_mae,
 * holdout_mae, final_holdout_mae, test_mae, dst. Hanya yang ditemukan yang dikembalikan; tidak ada angka tebakan.
 * Nilai MAPE diambil apa adanya (dianggap sudah dalam persen).
 */
class ModelMetadataReader
{
    private const SETS = [
        'wfv'     => ['wfv', 'walk_forward', 'validation'],
        'holdout' => ['holdout', 'final_holdout', 'test'],
    ];

    /** @return array<string, float> mis. ['wfv_mae' => 12.3, 'holdout_mape' => 1.2] */
    public function metrics(array $meta): array
    {
        $flat = [];
        $this->flatten($meta, '', $flat);

        $out = [];
        foreach (self::SETS as $set => $synonyms) {
            foreach (['mae', 'rmse', 'mape'] as $metric) {
                foreach ($synonyms as $syn) {
                    foreach (["{$syn}_{$metric}", "{$metric}_{$syn}"] as $candidate) {
                        foreach ($flat as $key => $value) {
                            if ($key === $candidate || str_ends_with($key, '_' . $candidate)) {
                                $out["{$set}_{$metric}"] = (float) $value;
                                break 3;
                            }
                        }
                    }
                }
            }
        }

        return $out;
    }

    private function flatten(array $data, string $prefix, array &$flat): void
    {
        foreach ($data as $key => $value) {
            $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($prefix . '_' . $key)), '_');
            if (is_array($value)) {
                $this->flatten($value, $name, $flat);
            } elseif (is_numeric($value) && ! is_string($value)) {
                $flat[$name] = $value;   // hanya angka sungguhan, bukan teks
            }
        }
    }
}
