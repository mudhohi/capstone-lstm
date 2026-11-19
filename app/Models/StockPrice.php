<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPrice extends Model
{
    protected $fillable = ['stock_id', 'date', 'open', 'high', 'low', 'close', 'volume'];

    protected $casts = ['date' => 'date'];

    public function stock(): BelongsTo { return $this->belongsTo(Stock::class); }
}
