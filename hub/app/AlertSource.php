<?php

namespace App;

/**
 * What raised an alert: the model's review, a deterministic hard rule, or a report that never arrived.
 */
enum AlertSource: string
{
    case Review = 'review';
    case Rule = 'rule';
    case MissingReport = 'missing_report';
}
