<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmail extends Notification
{
    use Queueable;

    public $user;
    public $password;

    /**
     * Create a new notification instance.
     */
    public function __construct($user, $password)
    {
        //
        $this->user = $user;
        $this->password = $password;
        // Kirim ke queue khusus (opsional tapi sangat direkomendasikan)
        $this->queue = 'emails'; // atau 'notifications'

        // Delay 10 detik (opsional)
        $this->delay(now()->addSeconds(10));
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mailData = [
            'name' => $this->user->username,
            'email' => $this->user->email,
            'password' =>$this->password,
            'force_password_change' =>$this->user->force_password_change,
        ];
        return (new MailMessage)
        ->subject('Register Account SiMAPA')
        ->view(
            'pages.mails.welcome', ['mailData' => $mailData]
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
