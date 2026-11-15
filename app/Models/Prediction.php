<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $fillable = [
        'pre_trained_model_id', 'stock_id', 'date', 'phase', 'fold',
        'actual_price', 'predicted_price', 'abs_error', 'pct_error',
    ];

    protected $casts = ['date' => 'date'];

    public function model(): BelongsTo { return $this->belongsTo(PreTrainedModel::class, 'pre_trained_model_id'); }
    public function stock(): BelongsTo { return $this->belongsTo(Stock::class); }
}
