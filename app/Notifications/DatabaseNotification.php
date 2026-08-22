<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi dalam aplikasi (lonceng), dan — bila diminta — email.
 *
 * Sampai 2026-08-23 kelas ini hanya mengembalikan `['database']`, sehingga TAK SATU PUN
 * peristiwa naskah maupun tugas pernah mengirim email. Yang berkirim email cuma empat
 * Mailable terpisah: invoice, refund, slip gaji, invoice layanan.
 *
 * Email dinyalakan PER PERISTIWA lewat `email` di payload, bukan untuk semuanya. Satu
 * naskah yang berjalan normal menghasilkan belasan notifikasi; mengirim semuanya sebagai
 * email membuat orang menyaringnya habis dalam sebulan — termasuk yang benar-benar
 * mendesak. Yang layak email hanyalah peristiwa yang MENUNTUT PERBUATAN penerimanya.
 *
 * ShouldQueue: pengiriman email menunggu SMTP, dan tak seorang pun boleh menunggu itu
 * di dalam request. Antreannya sudah jalan lewat schedule:run di produksi.
 */
class DatabaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array{
     *   category:string, title:string, message:string, url:string, icon:string,
     *   email?:bool, aksi?:string
     * } $payload
     */
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        $lewat = ['database'];

        // Email hanya bila diminta DAN penerimanya punya alamat. Akun tanpa email
        // (impor lama, akun layanan) tak boleh menggagalkan seluruh notifikasi.
        if (($this->payload['email'] ?? false) && filled($notifiable->email ?? null)) {
            $lewat[] = 'mail';
        }

        return $lewat;
    }

    public function toArray(object $notifiable): array
    {
        // `email` dan `aksi` tak ikut disimpan: keduanya urusan pengiriman, bukan isi
        // notifikasi yang dibaca di lonceng.
        return collect($this->payload)->except(['email', 'aksi'])->all();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('[SiMAPA] ' . $this->payload['title'])
            ->greeting('Halo ' . ($notifiable->name ?? 'rekan') . ',')
            ->line($this->payload['message'])
            ->action($this->payload['aksi'] ?? 'Buka di SiMAPA', $this->payload['url'])
            ->line('Email ini dikirim karena ada yang menunggu tindakanmu. '
                   . 'Perubahan lain cukup muncul sebagai notifikasi di dalam aplikasi.');
    }
}
