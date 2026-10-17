<x-mail::message>
# Pendaftaran Magang Sedang Ditinjau

Halo **{{ $intern->fullname }}**,

Terima kasih telah mendaftar program magang di **Seven Inc**.

Berkas pendaftaran Anda telah kami terima dan saat ini sedang dalam proses peninjauan oleh tim rekrutmen kami. Kami akan menginformasikan pembaruan status pendaftaran Anda secepatnya.

<x-mail::button :url="config('app.url') . '/login'">
Cek Status Pendaftaran
</x-mail::button>

Terima kasih,<br>
**Tim Rekrutmen Seven Inc**
</x-mail::message>
