// Analisa Teknikal: indikator dihitung di browser dari deret OHLCV, lalu digambar dengan ApexCharts.
// Baris masukan: [t_ms, open, high, low, close, volume]
import ApexCharts from 'apexcharts';

const UP = '#15803D';
const DOWN = '#B91C1C';
const FONT = 'IBM Plex Sans, sans-serif';
const SMA_COLORS = { 7: '#F2A93B', 14: '#0E7C86', 20: '#7C3AED', 50: '#64748B' };
const idr = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

/** Simple Moving Average; nilai null sampai tersedia n data. */
export function sma(values, n) {
    const out = Array(values.length).fill(null);
    let sum = 0;
    for (let i = 0; i < values.length; i++) {
        sum += values[i];
        if (i >= n) sum -= values[i - n];
        if (i >= n - 1) out[i] = sum / n;
    }
    return out;
}

/** Exponential Moving Average; diawali SMA n data pertama non-null. */
export function ema(values, n) {
    const out = Array(values.length).fill(null);
    const start = values.findIndex((x) => x !== null);
    if (start < 0 || values.length - start < n) return out;
    const k = 2 / (n + 1);
    let prev = 0;
    for (let i = start; i < start + n; i++) prev += values[i];
    prev /= n;
    out[start + n - 1] = prev;
    for (let i = start + n; i < values.length; i++) {
        prev = values[i] * k + prev * (1 - k);
        out[i] = prev;
    }
    return out;
}

/** MACD (12, 26, 9): garis MACD = EMA12 - EMA26, sinyal = EMA9 dari MACD, histogram = MACD - sinyal. */
export function macd(closes) {
    const e12 = ema(closes, 12);
    const e26 = ema(closes, 26);
    const line = closes.map((_, i) => (e12[i] !== null && e26[i] !== null ? e12[i] - e26[i] : null));
    const signal = ema(line, 9);
    const hist = line.map((m, i) => (m !== null && signal[i] !== null ? m - signal[i] : null));
    return { line, signal, hist };
}

/**
 * Support/Resistance otomatis dari swing high/low (pivot): titik yang paling tinggi/rendah
 * dalam jendela +-k hari. Pivot berdekatan (selisih <= tol) digabung; level dengan sentuhan
 * terbanyak dipilih. Level di bawah penutupan terakhir = support, di atasnya = resistance.
 */
export function snr(highs, lows, closes, k = 5, tol = 0.01, maxEach = 3) {
    const pivots = [];
    for (let i = k; i < highs.length - k; i++) {
        let isHigh = true;
        let isLow = true;
        for (let j = i - k; j <= i + k; j++) {
            if (highs[j] > highs[i]) isHigh = false;
            if (lows[j] < lows[i]) isLow = false;
        }
        if (isHigh) pivots.push(highs[i]);
        if (isLow) pivots.push(lows[i]);
    }

    pivots.sort((a, b) => a - b);
    const clusters = [];
    for (const p of pivots) {
        const c = clusters[clusters.length - 1];
        if (c && (p - c.avg) / c.avg <= tol) {
            c.sum += p;
            c.n += 1;
            c.avg = c.sum / c.n;
        } else {
            clusters.push({ sum: p, n: 1, avg: p });
        }
    }

    const last = closes[closes.length - 1];
    const support = clusters.filter((c) => c.avg <= last).sort((a, b) => b.n - a.n || b.avg - a.avg).slice(0, maxEach);
    const resistance = clusters.filter((c) => c.avg > last).sort((a, b) => b.n - a.n || a.avg - b.avg).slice(0, maxEach);

    return { support: support.map((c) => c.avg), resistance: resistance.map((c) => c.avg) };
}

function startIndex(data, range) {
    const months = { '3M': 3, '6M': 6, '1Y': 12 }[range];
    if (!months) return 0;
    const d = new Date(data[data.length - 1].t);
    d.setUTCMonth(d.getUTCMonth() - months);
    const idx = data.findIndex((r) => r.t >= d.getTime());
    return idx < 0 ? 0 : idx;
}

const base = (id, height, extra) => ({
    chart: {
        id, group: 'ta', type: 'line', height,
        animations: { enabled: false },
        toolbar: { show: id === 'ta-price', autoSelected: 'pan' },
        zoom: { enabled: true, type: 'x' },
        fontFamily: FONT,
    },
    grid: { borderColor: '#E2E8F0' },
    tooltip: { shared: true, intersect: false, x: { format: 'dd MMM yyyy' } },
    legend: { position: 'top', horizontalAlign: 'left' },
    ...extra,
});

/** Gambar 3 panel yang tersinkron (harga, volume, MACD). Mengembalikan daftar chart agar bisa dihancurkan lagi. */
export async function draw(refs, opts, old = []) {
    old.forEach((c) => { try { c.destroy(); } catch (e) { /* abaikan */ } });

    const { rows, mode, range, sma: smaOn, showSnr } = opts;
    const data = rows.map(([t, o, h, l, c, v]) => ({ t, o, h, l, c, v }));
    const closes = data.map((r) => r.c);
    const from = startIndex(data, range);
    const view = data.slice(from);
    const xy = (arr) => view.map((r, i) => ({ x: r.t, y: arr[from + i] }));

    // ---------- Panel 1: candlestick (atau garis penutupan) + SMA + SnR ----------
    const smaNs = Object.keys(smaOn).filter((n) => smaOn[n]).map(Number).sort((a, b) => a - b);
    const main = mode === 'ohlcv'
        ? { type: 'candlestick', name: 'Harga', data: view.map((r) => ({ x: r.t, y: [r.o, r.h, r.l, r.c] })) }
        : { type: 'line', name: 'Penutupan', data: view.map((r) => ({ x: r.t, y: r.c })) };

    const lines = smaNs.map((n) => ({ type: 'line', name: `SMA ${n}`, data: xy(sma(closes, n)) }));

    let annotations = {};
    if (showSnr && view.length > 12) {
        const lv = snr(view.map((r) => r.h), view.map((r) => r.l), view.map((r) => r.c));
        const mk = (y, color, tag) => ({
            y, borderColor: color, strokeDashArray: 5,
            label: { text: `${tag} ${idr.format(y)}`, position: 'left', textAnchor: 'start', borderColor: color, style: { color: '#fff', background: color, fontSize: '11px' } },
        });
        annotations = { yaxis: [...lv.support.map((y) => mk(y, UP, 'S')), ...lv.resistance.map((y) => mk(y, DOWN, 'R'))] };
    }

    const priceOpts = base('ta-price', 380, {
        series: [main, ...lines],
        colors: ['#0F2A43', ...smaNs.map((n) => SMA_COLORS[n])],
        stroke: { width: [mode === 'ohlcv' ? 1 : 2, ...smaNs.map(() => 1.6)] },
        plotOptions: { candlestick: { colors: { upward: UP, downward: DOWN } } },
        xaxis: { type: 'datetime', labels: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
        yaxis: { labels: { minWidth: 64, formatter: (v) => idr.format(v) } },
        annotations,
    });

    const charts = [new ApexCharts(refs.price, priceOpts)];

    // ---------- Panel 2: volume + Volume MA 20 (hanya jika ada OHLCV) ----------
    if (mode === 'ohlcv') {
        const volMa = sma(data.map((r) => r.v), 20);
        charts.push(new ApexCharts(refs.volume, base('ta-volume', 160, {
            series: [
                { type: 'bar', name: 'Volume', data: view.map((r) => ({ x: r.t, y: r.v, fillColor: r.c >= r.o ? UP : DOWN })) },
                { type: 'line', name: 'Volume MA 20', data: xy(volMa) },
            ],
            colors: ['#64748B', '#F2A93B'],
            stroke: { width: [0, 2] },
            plotOptions: { bar: { columnWidth: '80%' } },
            xaxis: { type: 'datetime', labels: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
            yaxis: { labels: { minWidth: 64, formatter: (v) => `${(v / 1e6).toFixed(0)} jt` } },
        })));
    }

    // ---------- Panel 3: MACD (12, 26, 9) ----------
    const m = macd(closes);
    charts.push(new ApexCharts(refs.macd, base('ta-macd', 200, {
        series: [
            { type: 'bar', name: 'Histogram', data: view.map((r, i) => ({ x: r.t, y: m.hist[from + i], fillColor: (m.hist[from + i] ?? 0) >= 0 ? UP : DOWN })) },
            { type: 'line', name: 'MACD', data: xy(m.line) },
            { type: 'line', name: 'Signal', data: xy(m.signal) },
        ],
        colors: ['#64748B', '#0E7C86', '#F2A93B'],
        stroke: { width: [0, 2, 2] },
        plotOptions: { bar: { columnWidth: '70%' } },
        xaxis: { type: 'datetime' },
        yaxis: { labels: { minWidth: 64, formatter: (v) => v.toFixed(1) } },
    })));

    await Promise.all(charts.map((c) => c.render()));

    return charts;
}
