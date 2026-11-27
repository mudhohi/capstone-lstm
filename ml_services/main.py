"""
Layanan prediksi LSTM (FastAPI) untuk dashboard Laravel.
Kontrak: GET /predict?ticker=BBCA&lookback=21&horizon=7      (horizon opsional, 1-20, default 1)
         -> {"ticker","lookback","horizon","next_date","next_close_pred","forecasts":[{"step","date","predicted_close"}],
             "last_data_date","data_source","scaler","model_type"}      (lihat PredictionService::nextDay)

Jalankan dari folder ml_services:
    pip install -r requirements.txt
    uvicorn main:app --host 127.0.0.1 --port 8001

Dua jenis model (dikenali dari nama file di tabel pre_trained_models):
  1. price_scaled   : model_{ticker}_{lookback}.keras  + scaler_{ticker}_{lookback}.joblib  + metadata_{ticker}_{lookback}.json
                      Masukan = `lookback` harga Close yang di-scale; keluaran = harga berikutnya.
  2. general_return : model_general_{lookback}.keras   + scaler_general_{lookback}.joblib   + metadata_general_{lookback}.json
                      Model global multi-emiten. Masukan = `lookback` LOG-RETURN harian (RobustScaler global);
                      keluaran = log-return berikutnya; harga = Close_terakhir x exp(return). Butuh lookback+1 harga.

Endpoint tambahan: GET /fetch-ohlcv?symbol=ARTO.JK&start=2023-11-25&end=2026-11-25
  Menarik OHLCV harian dari Yahoo Finance (yfinance, auto_adjust=False -> Close = penutupan mentah) untuk form admin Laravel.

Sumber data harga: tabel stock_prices (mengikuti dataset yang diunggah Admin), cadangan: predictions.actual_price.
"""
import json
import math
import os
import re
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


# Kunci cache memuat waktu modifikasi file: jika file diganti, versi baru otomatis dimuat.
@lru_cache(maxsize=8)
def load_model(path: str, mtime: float):
    from tensorflow import keras  # impor di sini supaya /health tetap cepat

    return keras.models.load_model(path, compile=False)


@lru_cache(maxsize=16)
def load_scaler(path: str, mtime: float):
    return joblib.load(path)


def fit_dynamic_scaler(ticker: str):
    """Hanya untuk model price_scaled lama tanpa scaler sendiri: fit MinMaxScaler pada seluruh Close emiten itu."""
    from sklearn.preprocessing import MinMaxScaler

    rows = query(
        "SELECT sp.close FROM stock_prices sp JOIN stocks s ON s.id = sp.stock_id WHERE s.ticker = ? ORDER BY sp.date",
        (ticker,),
    )
    if len(rows) < 30:
        raise HTTPException(409, f"Data harga {ticker} hanya {len(rows)} baris, terlalu sedikit untuk men-fit scaler.")
    return MinMaxScaler().fit(np.array([r[0] for r in rows], dtype="float64").reshape(-1, 1))


def last_closes(ticker: str, n: int, model_id: int) -> tuple[list[tuple], str]:
    """`n` penutupan terakhir (urut lama -> baru) dan nama sumbernya."""
    rows = query(
        "SELECT sp.date, sp.close FROM stock_prices sp JOIN stocks s ON s.id = sp.stock_id "
        "WHERE s.ticker = ? ORDER BY sp.date DESC LIMIT ?",
        (ticker, n),
    )
    source = "stock_prices"

    if not rows:  # cadangan: stock_prices belum diisi sama sekali
        rows = query(
            "SELECT date, actual_price FROM predictions WHERE pre_trained_model_id = ? "
            "AND actual_price IS NOT NULL ORDER BY date DESC LIMIT ?",
            (model_id, n),
        )
        source = "predictions"

    if len(rows) < n:
        raise HTTPException(409, f"Riwayat harga {ticker} hanya {len(rows)} hari ({source}), butuh {n}.")

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
    if not model_file.exists():
        raise HTTPException(500, f"File model tidak ditemukan: {model_file}")

    # 2. Jenis model dan lokasi scaler/metadata dari nama file
    folder = model_file.parent
    general = model_file.name.startswith("model_general_")
    tag = f"general_{lookback}" if general else f"{ticker.lower()}_{lookback}"
    scaler_file, meta_file = folder / f"scaler_{tag}.joblib", folder / f"metadata_{tag}.json"

    meta = json.loads(meta_file.read_text(encoding="utf-8")) if meta_file.exists() else {}
    meta_lb = meta.get("lookback_window", meta.get("lookback"))
    if meta_lb is not None and int(meta_lb) != lookback:
        raise HTTPException(409, f"Metadata menyebut lookback {meta_lb}, permintaan {lookback}.")
    if general and meta.get("model_type", "general_return") != "general_return":
        raise HTTPException(409, f"Metadata {meta_file.name} bukan model general_return.")

    # 3. Harga penutupan terakhir. Model return butuh satu harga tambahan untuk membentuk `lookback` return.
    last, data_source = last_closes(ticker, lookback + 1 if general else lookback, model_id)
    closes = [float(c) for _, c in last]

    model = load_model(str(model_file), model_file.stat().st_mtime)
    if scaler_file.exists():
        scaler, scaler_kind = load_scaler(str(scaler_file), scaler_file.stat().st_mtime), "saved"
    elif general:
        raise HTTPException(500, f"Scaler tidak ditemukan: {scaler_file}")
    else:
        scaler, scaler_kind = fit_dynamic_scaler(ticker), "dynamic"

    # 4. Prediksi rekursif (autoregressive): hasil tiap langkah menjadi masukan langkah berikutnya
    day = pd.Timestamp(last[-1][0])   # hari bursa pertama = setelah tanggal terakhir di data
    forecasts = []
    try:
        if general:
            returns = list(np.diff(np.log(closes)))          # `lookback` log-return harian terakhir
            price = closes[-1]
            for step in range(1, horizon + 1):
                x = scaler.transform(np.array(returns[-lookback:]).reshape(-1, 1)).reshape(1, lookback, 1).astype("float32")
                y = np.array(model(x, training=False))
                r = float(scaler.inverse_transform(y.reshape(-1, 1))[0, 0])   # prediksi log-return
                returns.append(r)
                price *= math.exp(r)                          # Close_t+1 = Close_t x e^r
                day = day + pd.offsets.BDay(1)                # lewati Sabtu-Minggu (libur bursa belum diperhitungkan)
                forecasts.append({"step": step, "date": day.strftime("%Y-%m-%d"), "predicted_close": round(price, 2)})
        else:
            window = closes[:]
            for step in range(1, horizon + 1):
                x = scaler.transform(np.array(window[-lookback:]).reshape(-1, 1)).reshape(1, lookback, 1)
                y = np.array(model(x, training=False))
                price = float(scaler.inverse_transform(y.reshape(-1, 1))[0, 0])
                window.append(price)                          # geser jendela: tambah prediksi, buang yang terlama
                day = day + pd.offsets.BDay(1)
                forecasts.append({"step": step, "date": day.strftime("%Y-%m-%d"), "predicted_close": round(price, 2)})
    except ValueError as e:
        raise HTTPException(500, f"Bentuk scaler/model tidak cocok dengan data masukan: {e}")

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
        "model_type": "general_return" if general else "price_scaled",
    }


@app.get("/fetch-ohlcv")
def fetch_ohlcv(
    symbol: str = Query(..., pattern=r"^[A-Za-z0-9.\-]{1,15}$"),
    start: str = Query(..., pattern=r"^\d{4}-\d{2}-\d{2}$"),
    end: str = Query(..., pattern=r"^\d{4}-\d{2}-\d{2}$"),
):
    """OHLCV harian dari Yahoo Finance untuk rentang start..end (end ikut diambil)."""
    symbol = symbol.upper()
    if re.fullmatch(r"[A-Z]{4}", symbol):
        symbol += ".JK"                      # toleran: "ARTO" -> "ARTO.JK"

    try:
        t_start, t_end = pd.Timestamp(start), pd.Timestamp(end)
    except (ValueError, TypeError):
        raise HTTPException(422, "Format tanggal tidak valid. Gunakan YYYY-MM-DD.")
    if t_start > t_end:
        raise HTTPException(422, "Tanggal mulai harus sebelum atau sama dengan tanggal akhir.")

    try:
        import yfinance as yf  # impor di sini supaya /health dan /predict tetap jalan tanpa yfinance
    except ImportError:
        raise HTTPException(500, "Paket yfinance belum terpasang di layanan Python. Jalankan: pip install yfinance")

    try:
        # parameter end di yfinance bersifat eksklusif -> tambah 1 hari agar tanggal akhir ikut terambil
        df = yf.download(
            symbol,
            start=t_start.strftime("%Y-%m-%d"),
            end=(t_end + pd.Timedelta(days=1)).strftime("%Y-%m-%d"),
            auto_adjust=False,               # Close = penutupan mentah (bukan Adj Close)
            progress=False,
        )
    except Exception as exc:  # jaringan, pembatasan laju Yahoo, dsb.
        raise HTTPException(502, f"Gagal mengambil data dari Yahoo Finance: {exc}")

    not_found = (f"Data untuk kode {symbol} tidak ditemukan di Yahoo Finance pada rentang {start} s.d. {end}. "
                 "Periksa kode saham (mis. ARTO.JK) dan rentang tanggal.")
    if df is None or df.empty:
        raise HTTPException(404, not_found)

    if isinstance(df.columns, pd.MultiIndex):        # yfinance terbaru: kolom bertingkat (Price, Ticker)
        df.columns = df.columns.get_level_values(0)

    need = ["Open", "High", "Low", "Close", "Volume"]
    missing = [c for c in need if c not in df.columns]
    if missing:
        raise HTTPException(502, f"Format data Yahoo Finance tidak dikenali (kolom hilang: {', '.join(missing)}).")

    df = df[need].dropna(subset=["Open", "High", "Low", "Close"]).copy()
    df["Volume"] = df["Volume"].fillna(0)
    if df.empty:
        raise HTTPException(404, not_found)

    dates = pd.to_datetime(df.index).strftime("%Y-%m-%d")
    rows = [
        {"date": d, "open": round(float(o), 4), "high": round(float(h), 4), "low": round(float(l), 4),
         "close": round(float(c), 4), "volume": int(v)}
        for d, (o, h, l, c, v) in zip(dates, df[need].itertuples(index=False, name=None))
    ]

    company = symbol
    try:
        info = yf.Ticker(symbol).info
        company = info.get("longName") or info.get("shortName") or symbol
    except Exception:
        pass                                         # nama perusahaan hanya pelengkap

    return {"symbol": symbol, "ticker_clean": symbol.split(".")[0], "company_name": company, "count": len(rows), "rows": rows}
