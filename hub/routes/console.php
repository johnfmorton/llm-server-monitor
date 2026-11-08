<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->dailyAt('03:30')->withoutOverlapping();
