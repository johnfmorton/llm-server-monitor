<?php

namespace App\Monitoring;

use App\AlertSource;
use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\RuleHit;
use Illuminate\Support\Facades\DB;

/**
 * Runs the hard rules on a summary and stores what they find. One hit and one
 * alert per rule per server-day: if a day is sent again, the hit's evidence
 * is updated but no second alert is raised.
 */
class RuleRecorder
{
    public function __construct(private HardRules $rules) {}

    /**
     * @return int the number of new alerts raised
     */
    public function record(DailySummary $summary): int
    {
        $hits = $this->rules->evaluate($summary->server, $summary->payload);
        if ($hits === []) {
            return 0;
        }

        return DB::transaction(function () use ($summary, $hits): int {
            $raised = 0;
            foreach ($hits as $hit) {
                $ruleHit = RuleHit::updateOrCreate(
                    ['server_id' => $summary->server_id, 'date' => $summary->date->toDateString(), 'rule' => $hit['rule']],
                    ['daily_summary_id' => $summary->id, 'title' => $hit['title'], 'evidence' => $hit['evidence']],
                );

                $alert = Alert::firstOrCreate(
                    ['rule_hit_id' => $ruleHit->id],
                    [
                        'server_id' => $summary->server_id,
                        'date' => $summary->date->toDateString(),
                        'source' => AlertSource::Rule,
                        'title' => $hit['title'],
                    ],
                );
                $raised += $alert->wasRecentlyCreated ? 1 : 0;
            }

            return $raised;
        });
    }
}
