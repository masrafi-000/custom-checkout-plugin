<?php
$log_file = ini_get('error_log');
if ($log_file && file_exists($log_file)) {
    $lines = file($log_file);
    $last_lines = array_slice($lines, -50);
    echo "Last 50 lines of error log ($log_file):\n";
    echo implode("", $last_lines);
} else {
    echo "Error log not found or not accessible. ini_get('error_log'): " . $log_file . "\n";
}
