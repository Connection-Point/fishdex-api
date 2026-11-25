<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fishdex:release-waiting-scans')->everyMinute()->withoutOverlapping();
Schedule::command('fishdex:sweep-stuck-scans')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fishdex:rollup-ai-usage')->hourly();
Schedule::command('fishdex:purge')->dailyAt('03:30')->timezone('Asia/Kuala_Lumpur');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
