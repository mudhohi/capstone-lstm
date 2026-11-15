<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model
{
    protected $fillable = ['ticker', 'name', 'sector', 'data_start_date', 'data_end_date', 'total_observations'];

    protected $casts = ['data_start_date' => 'date', 'data_end_date' => 'date'];

    public function models(): HasMany      { return $this->hasMany(PreTrainedModel::class); }
    public function predictions(): HasMany { return $this->hasMany(Prediction::class); }
    public function watchlists(): HasMany  { return $this->hasMany(Watchlist::class); }
}
