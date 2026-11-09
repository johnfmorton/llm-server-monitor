<?php

namespace App\Mail;

use App\Models\Alert;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class AlertMail extends Mailable
{
    /**
     * @param  Collection<int, Alert>  $alerts  with ruleHit and review loaded
     */
    public function __construct(
        public Server $server,
        public Carbon $day,
        public Collection $alerts,
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->alerts->first()->title;
        $more = $this->alerts->count() - 1;

        return new Envelope(
            subject: "[LogWatch] ALERT — {$this->server->name} — {$title}".($more > 0 ? " (+{$more} more)" : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.alert',
            text: 'mail.alert-text',
            with: [
                'dayUrl' => route('server-days.show', [$this->server, $this->day->toDateString()]),
            ],
        );
    }
}
