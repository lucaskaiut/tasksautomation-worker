<?php

namespace App\Mail;

use App\DTOs\TaskStatusNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TaskStatusNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly TaskStatusNotification $notification,
        public readonly string $messageBody,
    ) {}

    public function envelope(): Envelope
    {
        $result = strtoupper($this->notification->result);

        return new Envelope(
            subject: sprintf('[TaskAutomation] Task #%d %s', $this->notification->task->id, $result),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.task-status-notification',
            with: [
                'messageBody' => $this->messageBody,
            ],
        );
    }
}
