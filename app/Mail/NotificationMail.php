<?php

namespace App\Mail;

use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued HTML email for a notification (Phase 9). Rendered from the log row and
 * sent on the queue (ShouldQueue) with the framework's built-in retry handling.
 * Contains only user-facing summary data — no device tokens or credentials.
 */
class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly NotificationLog $log) {}

    public function envelope(): Envelope
    {
        $prefix = strtoupper($this->log->severity);

        return new Envelope(subject: "[Treck {$prefix}] {$this->log->title}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notification',
            with: [
                'log' => $this->log,
                'dashboardUrl' => route('notifications.index'),
            ],
        );
    }
}
