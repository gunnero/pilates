<?php

namespace App\Contracts\Accounts;

interface InvitationDelivery
{
    /** Transient secret: never log or persist the raw URL. Called after the linking transaction commits. */
    public function send(string $email, string $url, string $locale): void;
}
