"""
Layanan prediksi LSTM (FastAPI) untuk dashboard Laravel.
Kontrak: GET /predict?ticker=BBCA&lookback=21
         -> {"ticker","lookback","next_date","next_close_pred"}   (lihat PredictionService::nextDay)

Jalankan dari folder ml_services:
    pip install -r requirements.txt
    uvicorn main:app --host 127.0.0.1 --port 8001

Sumber data:
  - lokasi model : tabel pre_trained_models (kolom file_path) di database/database.sqlite
  - jendela harga: harga aktual terakhir pada tabel predictions (hasil impor lstm:import-results).
    Fase holdout (20% data terakhir) sudah memuat > 126 hari bursa berurutan, cukup untuk semua lookback.
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


@lru_cache(maxsize=8)
def load_artifacts(model_path: str, scaler_path: str, meta_path: str):
    """Model, scaler, dan metadata dimuat sekali lalu disimpan di memori."""
    from tensorflow import keras  # impor di sini supaya /health tetap cepat

    model = keras.models.load_model(model_path, compile=False)
    scaler = joblib.load(scaler_path)
    meta = json.loads(Path(meta_path).read_text(encoding="utf-8")) if Path(meta_path).exists() else {}
    return model, scaler, meta


@app.get("/health")
def health():
    return {"status": "ok", "db": str(DB_PATH), "db_exists": DB_PATH.exists()}


@app.get("/predict")
def predict(
    ticker: str = Query(..., pattern=r"^[A-Za-z]{4}$"),
    lookback: int = Query(..., ge=1),
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

    for f in (model_file, scaler_file):
        if not f.exists():
            raise HTTPException(500, f"File tidak ditemukan: {f}")

    # 2. Ambil harga penutupan terakhir sebanyak `lookback` hari
    last = query(
        "SELECT date, actual_price FROM predictions WHERE pre_trained_model_id = ? "
        "AND actual_price IS NOT NULL ORDER BY date DESC LIMIT ?",
        (model_id, lookback),
    )
    if len(last) < lookback:
        raise HTTPException(409, f"Riwayat harga hanya {len(last)} hari, butuh {lookback}. Jalankan lstm:import-results.")
    last.reverse()  # urut dari lama ke baru
    prices = np.array([p for _, p in last], dtype="float64").reshape(-1, 1)

    # 3. Scaling -> prediksi -> kembalikan ke skala Rupiah
    model, scaler, meta = load_artifacts(str(model_file), str(scaler_file), str(meta_file))

    meta_lb = meta.get("lookback_window", meta.get("lookback"))
    if meta_lb is not None and int(meta_lb) != lookback:
        raise HTTPException(409, f"Metadata menyebut lookback {meta_lb}, permintaan {lookback}.")

    try:
        scaled = scaler.transform(prices).reshape(1, lookback, 1)
        y = model.predict(scaled, verbose=0)
        price = float(scaler.inverse_transform(np.array(y).reshape(-1, 1))[0, 0])
    except ValueError as e:
        raise HTTPException(500, f"Bentuk scaler/model tidak cocok dengan data univariat (Close): {e}")

    # 4. Hari bursa berikutnya (Senin-Jumat; libur bursa belum diperhitungkan)
    next_date = (pd.Timestamp(last[-1][0]) + pd.offsets.BDay(1)).strftime("%Y-%m-%d")

    return {"ticker": ticker, "lookback": lookback, "next_date": next_date, "next_close_pred": round(price, 2)}
