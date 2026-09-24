<?php

namespace App\Support\SelfService;

use App\Contracts\Accounts\InvitationDelivery;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

final class MailInvitationDelivery implements InvitationDelivery
{
    public function send(string $email, string $url, string $locale): void
    {
        if (config('mail.mailers.'.config('mail.default').'.transport') === 'log') {
            throw new \LogicException('Secret-bearing invitations cannot use log transport');
        }
        Mail::raw(__('selfservice.invite_message', ['url' => $url], $locale), function (Message $m) use ($email, $locale) {
            $m->to($email)->subject(__('selfservice.invite_subject', [], $locale));
        });
    }
}
