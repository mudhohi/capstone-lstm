<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreTrainedModel extends Model
{
    /** Variasi lookback window (hari perdagangan) sesuai desain eksperimen. */
    public const LOOKBACKS = [21, 42, 63, 126];

    protected $fillable = [
        'stock_id', 'lookback_window', 'file_path', 'file_format', 'architecture',
        'wfv_folds', 'wfv_mae', 'wfv_rmse', 'wfv_mape',
        'holdout_mae', 'holdout_rmse', 'holdout_mape',
        'trained_at', 'is_active', 'is_transfer', 'base_model_id', 'model_type',
    ];

    protected $casts = [
        'architecture' => 'array',
        'trained_at' => 'datetime',
        'is_active' => 'boolean',
        'is_transfer' => 'boolean',
    ];

    /** Jenis model dikunci dari nama file, sehingga form Kelola model pun tidak bisa salah label. */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if (str_starts_with(basename((string) $m->file_path), 'model_general_')) {
                $m->model_type = 'general_return';
            }
        });
    }

    public function isGeneral(): bool
    {
        return $this->model_type === 'general_return';
    }

    public function stock(): BelongsTo      { return $this->belongsTo(Stock::class); }
    public function predictions(): HasMany  { return $this->hasMany(Prediction::class); }
    public function baseModel(): BelongsTo  { return $this->belongsTo(self::class, 'base_model_id'); }
}
