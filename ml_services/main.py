"""
Layanan prediksi LSTM (FastAPI) untuk dashboard Laravel.
Kontrak: GET /predict?ticker=BBCA&lookback=21&horizon=7      (horizon opsional, 1-20, default 1)
         -> {"ticker","lookback","horizon","next_date","next_close_pred","forecasts":[{"step","date","predicted_close"}],
             "last_data_date","data_source","scaler"}          (lihat PredictionService::nextDay)

Jalankan dari folder ml_services:
    pip install -r requirements.txt
    uvicorn main:app --host 127.0.0.1 --port 8001

Sumber data:
  - lokasi model : tabel pre_trained_models (kolom file_path) di database/database.sqlite
  - jendela harga: `lookback` penutupan TERAKHIR dari tabel stock_prices (mengikuti dataset yang diunggah Admin).
                   Hanya jika stock_prices kosong, dipakai cadangan: harga aktual di tabel predictions.
  - scaler       : scaler_{ticker}_{lookback}.joblib bila ada. Bila tidak ada (emiten baru dengan model transfer),
                   MinMaxScaler di-fit pada seluruh penutupan emiten itu di stock_prices.
"""
import json
import os
import sqlite3
from functools import lru_cache
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from fastapi import FastAPI, HTTPException, Query

BASE_DIR = Path(__file__).resolve().parent.parent  # root proyek Laravel
DB_PATH = Path(os.getenv("DB_DATABASE", BASE_DIR / "database" / "database.sqlite"))

app = FastAPI(title="Lookback Bank - LSTM service")


def query(sql: str, params: tuple = ()) -> list[tuple]:
    """Baca SQLite Laravel (read-only agar tidak mengunci file saat Laravel menulis)."""
    con = sqlite3.connect(f"{DB_PATH.as_uri()}?mode=ro", uri=True)
    try:
        return con.execute(sql, params).fetchall()
    finally:
        con.close()


# Kunci cache memuat waktu modifikasi file: jika file diganti (mis. unggah ulang), versi baru otomatis dimuat.
@lru_cache(maxsize=8)
def load_model(path: str, mtime: float):
    from tensorflow import keras  # impor di sini supaya /health tetap cepat

    return keras.models.load_model(path, compile=False)


@lru_cache(maxsize=16)
def load_scaler(path: str, mtime: float):
    return joblib.load(path)


def fit_dynamic_scaler(ticker: str):
    """Scaler untuk emiten tanpa scaler sendiri: fit pada seluruh penutupan emiten itu di stock_prices."""
    from sklearn.preprocessing import MinMaxScaler

    rows = query(
        "SELECT sp.close FROM stock_prices sp JOIN stocks s ON s.id = sp.stock_id WHERE s.ticker = ? ORDER BY sp.date",
        (ticker,),
    )
    if len(rows) < 30:
        raise HTTPException(409, f"Data harga {ticker} hanya {len(rows)} baris, terlalu sedikit untuk men-fit scaler.")
    return MinMaxScaler().fit(np.array([r[0] for r in rows], dtype="float64").reshape(-1, 1))


def last_closes(ticker: str, lookback: int, model_id: int) -> tuple[list[tuple], str]:
    """`lookback` penutupan terakhir (urut lama -> baru) dan nama sumbernya."""
    rows = query(
        "SELECT sp.date, sp.close FROM stock_prices sp JOIN stocks s ON s.id = sp.stock_id "
        "WHERE s.ticker = ? ORDER BY sp.date DESC LIMIT ?",
        (ticker, lookback),
    )
    source = "stock_prices"

    if not rows:  # cadangan: stock_prices belum diisi sama sekali
        rows = query(
            "SELECT date, actual_price FROM predictions WHERE pre_trained_model_id = ? "
            "AND actual_price IS NOT NULL ORDER BY date DESC LIMIT ?",
            (model_id, lookback),
        )
        source = "predictions"

    if len(rows) < lookback:
        raise HTTPException(409, f"Riwayat harga {ticker} hanya {len(rows)} hari ({source}), butuh {lookback}.")

    rows.reverse()
    return rows, source


@app.get("/health")
def health():
    return {"status": "ok", "db": str(DB_PATH), "db_exists": DB_PATH.exists()}


@app.get("/predict")
def predict(
    ticker: str = Query(..., pattern=r"^[A-Za-z]{4}$"),
    lookback: int = Query(..., ge=1),
    horizon: int = Query(1, ge=1, le=20),
):
    ticker = ticker.upper()

    # 1. Cari model aktif untuk kombinasi saham + lookback
    rows = query(
        """SELECT m.id, m.file_path FROM pre_trained_models m
           JOIN stocks s ON s.id = m.stock_id
           WHERE s.ticker = ? AND m.lookback_window = ? AND m.is_active = 1""",
        (ticker, lookback),
    )
    if not rows:
        raise HTTPException(404, f"Tidak ada model aktif untuk {ticker} lookback {lookback}.")
    model_id, file_path = rows[0]

    model_file = Path(file_path)
    if not model_file.is_absolute():
        model_file = BASE_DIR / model_file
    folder, tag = model_file.parent, f"{ticker.lower()}_{lookback}"
    scaler_file, meta_file = folder / f"scaler_{tag}.joblib", folder / f"metadata_{tag}.json"

    if not model_file.exists():
        raise HTTPException(500, f"File model tidak ditemukan: {model_file}")

    # 2. Harga penutupan terakhir dari stock_prices (cadangan: predictions)
    last, data_source = last_closes(ticker, lookback, model_id)
    window = [float(c) for _, c in last]  # harga Rupiah, urut lama -> baru

    # 3. Model, scaler (tersimpan atau dinamis), metadata
    model = load_model(str(model_file), model_file.stat().st_mtime)
    if scaler_file.exists():
        scaler, scaler_kind = load_scaler(str(scaler_file), scaler_file.stat().st_mtime), "saved"
    else:
        scaler, scaler_kind = fit_dynamic_scaler(ticker), "dynamic"

    meta = json.loads(meta_file.read_text(encoding="utf-8")) if meta_file.exists() else {}
    meta_lb = meta.get("lookback_window", meta.get("lookback"))
    if meta_lb is not None and int(meta_lb) != lookback:
        raise HTTPException(409, f"Metadata menyebut lookback {meta_lb}, permintaan {lookback}.")

    # 4. Prediksi rekursif (autoregressive): hasil tiap langkah menjadi masukan langkah berikutnya
    day = pd.Timestamp(last[-1][0])   # hari bursa pertama = setelah tanggal terakhir di data
    forecasts = []
    try:
        for step in range(1, horizon + 1):
            x = scaler.transform(np.array(window[-lookback:]).reshape(-1, 1)).reshape(1, lookback, 1)
            y = np.array(model(x, training=False))
            price = float(scaler.inverse_transform(y.reshape(-1, 1))[0, 0])
            window.append(price)                      # geser jendela: tambah prediksi, buang yang terlama
            day = day + pd.offsets.BDay(1)            # lewati Sabtu-Minggu (libur bursa belum diperhitungkan)
            forecasts.append({"step": step, "date": day.strftime("%Y-%m-%d"), "predicted_close": round(price, 2)})
    except ValueError as e:
        raise HTTPException(500, f"Bentuk scaler/model tidak cocok dengan data univariat (Close): {e}")

    return {
        "ticker": ticker,
        "lookback": lookback,
        "horizon": horizon,
        "next_date": forecasts[0]["date"],              # kompatibel dengan versi sebelumnya
        "next_close_pred": forecasts[0]["predicted_close"],
        "forecasts": forecasts,
        "last_data_date": str(last[-1][0])[:10],
        "data_source": data_source,
        "scaler": scaler_kind,
    }
