<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Security\Crypto;

final class JobRunner
{
    public function __construct(
        private readonly JobQueueService $jobs,
        private readonly MailService $mail,
        private readonly Crypto $crypto,
        private readonly WebhookService $webhooks,
        private readonly NotificationService $notifications,
    ){}

    public function run(int $limit=50):array
    {
        return $this->jobs->process($limit,[
            'email'=>fn(array $p)=>$this->mail->send((string)$p['to'],(string)$p['subject'],(string)$p['html'],(string)$p['text']),
            'encrypted_email'=>function(array $p):void{
                $data=json_decode($this->crypto->decrypt((string)($p['envelope']??'')),true,512,JSON_THROW_ON_ERROR);
                $this->mail->send((string)$data['to'],(string)$data['subject'],(string)$data['html'],(string)$data['text']);
            },
            'notification_email'=>function(array $p):void{
                $message=$this->notifications->emailForNotification((int)($p['user_id']??0),(int)($p['notification_id']??0));
                if($message)$this->mail->send($message['to'],$message['subject'],$message['html'],$message['text']);
            },
            'notification_digest'=>function(array $p):void{
                $message=$this->notifications->digestEmail((int)($p['user_id']??0),(string)($p['from']??''),(string)($p['to']??''));
                if($message)$this->mail->send($message['to'],$message['subject'],$message['html'],$message['text']);
            },
            'webhook'=>fn(array $p)=>$this->webhooks->deliver($p),
        ]);
    }
}
