<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\Watchlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function index(Request $request)
    {
        $items = Watchlist::with('stock')->where('user_id', $request->user()->id)->latest()->get();

        // Saham yang belum ada di watchlist -> isi dropdown "Tambah saham"
        $available = Stock::whereNotIn('id', $items->pluck('stock_id'))->orderBy('ticker')->get();

        return view('watchlists.index', compact('items', 'available'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['stock_id' => ['required', 'exists:stocks,id']]);

        Watchlist::firstOrCreate(['user_id' => $request->user()->id, 'stock_id' => $data['stock_id']]);

        return back()->with('status', 'Saham ditambahkan ke watchlist.');
    }

    public function destroy(Request $request, Stock $stock): RedirectResponse
    {
        Watchlist::where('user_id', $request->user()->id)->where('stock_id', $stock->id)->delete();

        return back()->with('status', "{$stock->ticker} dihapus dari watchlist.");
    }
}
