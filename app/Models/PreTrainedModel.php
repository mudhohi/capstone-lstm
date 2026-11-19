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
        'trained_at', 'is_active', 'is_transfer', 'base_model_id',
    ];

    protected $casts = [
        'architecture' => 'array',
        'trained_at' => 'datetime',
        'is_active' => 'boolean',
        'is_transfer' => 'boolean',
    ];

    public function stock(): BelongsTo      { return $this->belongsTo(Stock::class); }
    public function predictions(): HasMany  { return $this->hasMany(Prediction::class); }
    public function baseModel(): BelongsTo  { return $this->belongsTo(self::class, 'base_model_id'); }
}
