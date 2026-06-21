<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class DatabaseNotification extends Notification
{
    /** @param array{category:string,title:string,message:string,url:string,icon:string} $payload */
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
