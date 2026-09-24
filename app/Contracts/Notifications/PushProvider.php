<?php

namespace App\Contracts\Notifications;

interface PushProvider
{
    /** @param list<array{to:string,title:string,body:string,data:array{url:string}}> $messages
     * @return list<array{status:string,id?:string,error?:string}>
     */
    public function send(array $messages): array;

    /** @param list<string> $ids
     * @return array<string,array{status:string,error?:string}>
     */
    public function receipts(array $ids): array;
}
