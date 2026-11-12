<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DigestMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $digest  from WeeklyDigest::build()
     */
    public function __construct(public array $digest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[LogWatch] Week of '.$this->digest['start']->format('j M').' — '.$this->digest['headline'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.digest',
            text: 'mail.digest-text',
            with: [
                'digestUrl' => route('digest', ['week' => $this->digest['start']->toDateString()]),
            ],
        );
    }
}
