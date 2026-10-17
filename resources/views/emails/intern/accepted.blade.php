<x-mail::message>
# Selamat, Pendaftaran Magang Anda Diterima!

Halo **{{ $intern->fullname }}**,

Selamat! Pendaftaran magang Anda di **Seven Inc** telah **Diterima**.

**Detail Pendaftaran:**
- **Nama:** {{ $intern->fullname }}
- **Asal Kampus / Sekolah:** {{ $intern->institution_name ?? '-' }}
- **Program Studi:** {{ $intern->study_program ?? '-' }}
- **Rencana Mulai Magang:** {{ $intern->start_date ? \Carbon\Carbon::parse($intern->start_date)->translatedFormat('d F Y') : '-' }}
- **Durasi Magang:** {{ $intern->planned_duration ?? '-' }}

Silakan login ke akun Anda untuk melihat arahan dan informasi penting selanjutnya.

<x-mail::button :url="config('app.url') . '/login'">
Buka Dashboard
</x-mail::button>

Terima kasih,<br>
**Tim Rekrutmen Seven Inc**
</x-mail::message>
