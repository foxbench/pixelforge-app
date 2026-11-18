<?php

use Illuminate\Support\Facades\Schedule;

// Webhooks can be lost; this fetches the status of anything that has gone quiet.
Schedule::command('app:poll-generations')->everyMinute()->withoutOverlapping(10);
