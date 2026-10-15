<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountActivationNotification extends Notification
{
    use Queueable;

    public string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('user.activate', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Aktivasi Akun Magang - Internship Management System')
            ->greeting('Halo, ' . ($notifiable->name ?? 'Peserta Magang') . '!')
            ->line('Akun Anda telah didaftarkan melalui integrasi Absen Djuragan.')
            ->line('Untuk dapat masuk ke portal magang, silakan aktifkan akun Anda dan buat kata sandi baru melalui tombol di bawah ini:')
            ->action('Aktivasi Akun & Setel Password', $url)
            ->line('Tautan aktivasi ini berlaku selama ' . config('auth.passwords.users.expire', 60) . ' menit.')
            ->line('Jika Anda tidak merasa mendaftar program magang ini, abaikan email ini.');
    }
}
