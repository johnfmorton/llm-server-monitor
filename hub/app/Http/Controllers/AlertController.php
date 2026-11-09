<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\RedirectResponse;

class AlertController extends Controller
{
    /**
     * Mark an alert as seen. An acknowledged alert is no longer emailed or shown in red on the grid.
     */
    public function acknowledge(Alert $alert): RedirectResponse
    {
        if ($alert->acknowledged_at === null) {
            $alert->forceFill(['acknowledged_at' => now()])->save();
        }

        return redirect()
            ->route('server-days.show', [$alert->server, $alert->date->toDateString()])
            ->with('status', 'Alert acknowledged.');
    }
}
