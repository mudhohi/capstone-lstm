# Jalankan di Google Colab (sel terakhir notebook). Hasilnya: ohlcv_bbca.csv, ohlcv_bbni.csv, ohlcv_bbri.csv, ohlcv_bmri.csv
# dengan header persis:  date,open,high,low,close,volume
import os
import pandas as pd

OUT = '/content/drive/MyDrive/capstone-lstm/ohlcv'   # ubah sesuai folder Drive Anda
os.makedirs(OUT, exist_ok=True)

def export_ohlcv(df, ticker):
    """df: DataFrame harga harian MENTAH yang sama dengan yang dipakai training (index = tanggal)."""
    d = df.copy()
    if isinstance(d.columns, pd.MultiIndex):          # hasil yfinance versi baru memakai kolom bertingkat
        d.columns = d.columns.get_level_values(0)
    d.index.name = 'date'
    d = d.reset_index()
    d.columns = [str(c).lower() for c in d.columns]   # Date/Open/High/Low/Close/Volume -> huruf kecil
    d = d[['date', 'open', 'high', 'low', 'close', 'volume']]
    d['date'] = pd.to_datetime(d['date']).dt.strftime('%Y-%m-%d')
    d = d[(d['date'] >= '2021-11-10') & (d['date'] <= '2026-11-06')].sort_values('date')
    d.to_csv(f'{OUT}/ohlcv_{ticker.lower()}.csv', index=False)
    print(ticker, len(d), 'baris', d['date'].min(), 's.d.', d['date'].max())

# Ganti nama variabel di bawah dengan DataFrame OHLCV tiap saham di notebook Anda:
# export_ohlcv(df_bbca, 'BBCA')
# export_ohlcv(df_bbni, 'BBNI')
# export_ohlcv(df_bbri, 'BBRI')
# export_ohlcv(df_bmri, 'BMRI')
