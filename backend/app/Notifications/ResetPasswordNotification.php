<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de redefinição de senha (D.2 v1.7), em pt_BR e enviado pela fila. O link aponta para a tela
 * do frontend (`app.frontend_url`/redefinir-senha); o nome do sistema vem de config('app.name').
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $token)
    {
        // Só vai para a fila depois do commit do pedido (token gravado e audit registrado).
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) config('app.name');
        $minutes = (int) config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject("Redefinição de senha — {$app}")
            ->greeting('Olá!')
            ->line("Recebemos um pedido para redefinir a senha da sua conta no {$app}.")
            ->action('Redefinir senha', $this->url($notifiable))
            ->line("O link vale por {$minutes} minutos e pode ser usado uma única vez.")
            ->line('Se você não fez esse pedido, ignore este e-mail: a sua senha continua a mesma.')
            ->salutation("Atenciosamente,\n{$app}");
    }

    /** `${APP_FRONTEND_URL}/redefinir-senha?token=…&email=…` */
    public function url(object $notifiable): string
    {
        return config('app.frontend_url').'/redefinir-senha?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
