<?php
# vim: set ts=4 sts=4 sw=4 et:

function duration($end) {
    $DAY = 86400;
    $HOUR = 3600;

    $now = time();
    $diff = $now - $end;
    $days = floor($diff / $DAY);
    $hours = floor(($diff % $DAY) / $HOUR);
    $minutes = floor((($diff % $DAY) % $HOUR) / 60);
    $secs = $diff % 60;
    return sprintf("%dd, %02d:%02d:%02d", $days, $hours, $minutes, $secs);
}

function hostsTable($nagios, $hosts) {
    echo '<table class="hostsTable" cellspacing="1" cellpadding="1">';
    echo '<tr><th width="30%">Host</th><th width="20%">Status</th><th width="20%">Duration</th><th width="30%">Status Information</th></tr>';
    foreach($hosts['down'] as $host) {
        $state = $nagios["host"][$host["current_state"]];
        echo "<tr class='".$state."'>\n";
        echo "<td class='hostname'>{$host["host_name"]}</td>\n";
        echo "<td class='state'>{$state}</td>\n";
        echo "<td class='duration'>".duration($host["last_state_change"])."</td>\n";
        echo "<td class='output'>" . htmlspecialchars($host['plugin_output']) . "</td>\n";
        echo "</tr>\n";
    }
    echo '</table>';
}

function servicesTable($nagios, $services, $select = false, $type = false) {
    global $maxOutputLength;

    print(sprintf("<table class=\"servicesTable\" cellspacing=\"1\" cellpadding=\"1\"><tr class='%s'>\n", ($type ? $type : '')));
    // TODO: move percentages to the CSS file
    print(
        '<th width="20%">Host</th>'.
        '<th width="20%">Service</th>'.
        '<th width="10%">Status</th>'.
        '<th width="15%">Duration</th>'.
        '<th width="5%">Attempts</th>'.
        '<th width="30%">Plugin Output</th>'."\n"
    );
    print("</tr>\n");

    foreach ($select as $selectedType) {
        if (isset($services[$selectedType]) && !empty($services[$selectedType])) {
            foreach ($services[$selectedType] as $service) {
                $state = $nagios["service"][$service["current_state"]];
                if (false === $type) {
                    $rowType = $state;
                } else {
                    $rowType = $type;
                    if ("acknowledged" !== $type) {
                        $state = $type;
                    }
                }
                print(sprintf("<tr class='%s'>\n", $rowType));
                print(sprintf("<td class='hostname'>%s</td>\n", $service['host_name']));
                print(sprintf("<td class='service'>%s</td>\n", $service['service_description']));
                print(sprintf("<td class='state'>%s", $state));
                if ($service["current_attempt"] < $service["max_attempts"]) {
                    print(" (Soft)");
                }
                print("</td>\n");
                print(sprintf("<td class='duration'>%s</td>\n", duration($service['last_state_change'])));
                print(sprintf("<td class='attempts'>%s/%s</td>\n", $service['current_attempt'], $service['max_attempts']));
                $plugin_output = str_split($service['plugin_output'], $maxOutputLength);
                $plugin_output = $plugin_output[0] . ((count($plugin_output) > 1) ? '...' : '');
                echo '<td class="output">' . htmlspecialchars(strip_tags($plugin_output)) . '</td>' . "\n";

                print("</tr>\n");
            }
        }
    }
    print("</table>\n");
}

function sectionHeader($section, $counter) {
    echo '<div id="' . $section . '" class="section">';
    echo '<h2 class="sectionTitle">' . ucfirst($section) . '</h2>';
    echo '<div class="stats">';
    foreach($counter[$section] as $type => $value) {
        if ($value > 0) {
            echo '<div class="stat ' . $type . '">' . strtoupper($type) . ': ' . $value . '</div>';
        }
    }
    echo '</div></div>';
}

function parse_status_file($statusFile, $statusFileTimeout) {

    // Check if status file is readable
    if (!is_readable($statusFile)) {
        return array('status' => 'error', 'message' => "Failed to read nagios status from '$statusFile'");
    }

    // Check if the status file is fresh enough
    if ((time() - filemtime($statusFile)) > $statusFileTimeout) {
        return array('status' => 'error', 'message' => 'Status File is too old');
    }

    $nagiosStatusData = file($statusFile);
    $in = false;
    $type = "unknown";
    $status = array();
    $host = null;

    $lineCount = count($nagiosStatusData);
    for($i = 0; $i < $lineCount; $i++) {
        if(false === $in) {
            $pos = strpos($nagiosStatusData[$i], "{");
            if (false !== $pos) {
                $in = true;
                $type = substr($nagiosStatusData[$i], 0, $pos-1);
                if(!empty($status[$type])) {
                    $arrPos = count($status[$type]);
                } else {
                    $arrPos = 0;
                }
                continue;
            }
        } else {
            $pos = strpos($nagiosStatusData[$i], "}");
            if (false !== $pos) {
                $in = false;
                $type = "unknown";
                $host = null;
                continue;
            }

            // Line with data found
            list($key, $value) = explode("=", trim($nagiosStatusData[$i]), 2);
            if ("hoststatus" === $type) {
                if ("host_name" === $key) {
                    $host = $value;
                }
                $status[$type][$host][$key] = $value;
            } else {
                $status[$type][$arrPos][$key] = $value;
            }
        }
    }
    
    return array('status' => 'ok', 'data' => array('status' => $status));
}

function get_nagios_status($nagios, $statusFile, $statusFileTimeout) {

    $nagios_status_data = parse_status_file($statusFile, $statusFileTimeout);

    if ($nagios_status_data['status'] != 'ok') {
        return $nagios_status_data;
    }

    $status = $nagios_status_data['data']['status'];
    $counter = array(
        'hosts' => array(
            'ok' => 0,
            'down' => 0,
            'unreachable' => 0,
            'acknowledged' => 0,
            'notification' => 0,
            'pending' => 0
        ),
        'services' => array(
            'ok' => 0,
            'warning' => 0,
            'critical' => 0,
            'unknown' => 0,
            'acknowledged' => 0,
            'notification' => 0,
            'pending' => 0,
        )
    );
    $states = array();

    foreach (array_keys($status) as $type) {
        switch ($type) {
            case "hoststatus":
                $hosts = $status[$type];
                foreach ($hosts as $host) {
                    if ((int)$host['scheduled_downtime_depth'] > 0) {
                        continue;
                    } else if ($host['problem_has_been_acknowledged'] == '1') {
                        $counter['hosts']['acknowledged']++;
                        $states['hosts']['acknowledged'][] = $host['host_name'];
                    } else if ($host['notifications_enabled'] == 0) {
                        $counter['hosts']['notification']++;
                        $states['hosts']['notification'][] = $host['host_name'];
                    } else if ($host['has_been_checked'] == 0) {
                        $counter['hosts']['pending']++;
                        $states['hosts']['pending'][] = $host['host_name'];
                    } else {
                        switch ($host['current_state']) {
                            case $nagios['host']['ok']:
                                $counter['hosts']['ok']++;
                                break;
                            case $nagios['host']['down']:
                                $counter['hosts']['down']++;
                                $states['hosts']['down'][] = $host;
                                break;
                            case $nagios['host']['unreachable']:
                                $counter['hosts']['unreachable']++;
                                $states['hosts']['unreachable'][] = $host['host_name'];
                                break;
                        }
                    }
                }
                break;

            case "servicestatus":
                $services = $status[$type];
                foreach ($services as $service) {
                    // Ignore all services if host state is not ok
                    $state = $status['hoststatus'][$service['host_name']]['current_state'];
                    if ($nagios['host']['ok'] != $state) {
                        continue;
                    }

                    if ((int)$service['scheduled_downtime_depth'] > 0) {
                        continue;
                    } else if ($service['problem_has_been_acknowledged'] == '1') {
                        $counter['services']['acknowledged']++;
                        $states['services']['acknowledged'][] = $service;
                    } else if ($service['notifications_enabled'] == '0') {
                        $counter['services']['notification']++;
                        $states['services']['notification'][] = $service;
                    } else if ($service['has_been_checked'] == '0') {
                        $counter['services']['pending']++;
                        $states['services']['pending'][] = $service;
                    } else {
                        switch ($service['current_state']) {
                            case $nagios['service']['ok']:
                                $counter['services']['ok']++;
                                break;
                            case $nagios['service']['warning']:
                                $counter['services']['warning']++;
                                $states['services']['warning'][] = $service;
                                break;
                            case $nagios['service']['critical']:
                                $counter['services']['critical']++;
                                $states['services']['critical'][] = $service;
                                break;
                            case $nagios['service']['unknown']:
                                $counter['services']['unknown']++;
                                $states['services']['unknown'][] = $service;
                                break;
                        }
                    }
                }
                break;
        }
    }

    return array('status' => 'ok', 'data' => array('counter' => $counter, 'states' => $states));
}

?>
