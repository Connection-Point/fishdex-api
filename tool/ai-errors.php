<?php
// Prints the latest AI attempts with their errors. Usage (Windows cmd, from fishdex-api):
//   php artisan tinker --env=tidb --execute="require 'tool/ai-errors.php';"
// Drop --env=tidb to read your local database instead.
foreach (DB::table('identification_attempts')->orderByDesc('id')->limit(6)->get() as $a) {
    echo str_repeat('-', 60), PHP_EOL;
    echo "#{$a->id}  {$a->created_at}  pass={$a->pass}  model={$a->model}  status={$a->status}  http={$a->http_status}  {$a->latency_ms}ms  tokens in/out={$a->input_tokens}/{$a->output_tokens}", PHP_EOL;
    echo 'error: ', $a->error_message ?? '-', PHP_EOL;
}
echo str_repeat('-', 60), PHP_EOL;
foreach (DB::table('identification_requests')->orderByDesc('id')->limit(3)->get(['id', 'status', 'error_code', 'created_at']) as $r) {
    echo "scan #{$r->id} {$r->created_at} status={$r->status} error_code=", $r->error_code ?? '-', PHP_EOL;
}
