<?php

// Set file path to your nagios status log
$statusFile = '/var/log/nagios/status.dat';

// Show warning state if status file was last updated <num> seconds ago
// Set this to a higher value then status_update_interval in your nagios.cfg
$statusFileTimeout = 60;

// Refresh time in seconds
$refresh = 10; 

// Maximum length of the Plugin Output text we would like to display
$maxOutputLength = 120;

// Timezone
$timezone = 'UTC';

// Page Title
$page_title = 'Nagios Status';

?>
