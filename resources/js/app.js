// Livewire 3 sudah membawa Alpine.js, jadi Alpine TIDAK diimpor lagi di sini
// (dobel Alpine menyebabkan error). Layout memuat @livewireScripts di semua halaman.
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts; // dipakai komponen Alpine di stock-prediction.blade.php
