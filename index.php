<?php
/**
 *  Naglite3 - Nagios Status Monitor
 *  Inspired by Naglite (http://www.monitoringexchange.org/inventory/Utilities/AddOn-Projects/Frontends/NagLite)
 *  and Naglite2 (http://laur.ie/blog/2010/03/naglite2-finally-released/)
 *
 *  @author     Steffen Zieger <me@saz.sh>
 *  @version    1.6
 *  @license    GPL
 **/

require './config.php';
require './lib.php';

// If there is a config file with local modifications, require it to overwrite some values
$local_config = './config.local.php';
if (file_exists($local_config)) {
    require $local_config;
}

if (!empty($_GET["refresh"]) && is_numeric($_GET["refresh"])) {
    $refresh = $_GET["refresh"];
}

$output_format = 'html';
if (isset($_GET['format']) && !empty($_GET['format'])) {
    $output_format = $_GET['format'];
}

// Set time zone
if (isset($timezone) && !empty($timezone)) {
    date_default_timezone_set($timezone);
}

// Nagios Status Map
$nagios["host"]["ok"] = 0;
$nagios["host"]["down"] = 1;
$nagios["host"]["unreachable"] = 2;
$nagios["host"][0] = "ok";
$nagios["host"][1] = "down";
$nagios["host"][2] = "unreachable";

$nagios["service"]["ok"] = 0;
$nagios["service"]["warning"] = 1;
$nagios["service"]["critical"] = 2;
$nagios["service"]["unknown"] = 3;
$nagios["service"][0] = "ok";
$nagios["service"][1] = "warning";
$nagios["service"][2] = "critical";
$nagios["service"][3] = "unknown";

// Get status information
$nagios_status = get_nagios_status($nagios, $statusFile, $statusFileTimeout);

// Output collected information
if ($output_format == 'json') {
    header('Cache-Control: no-cache');
    $callback = '';
    if (isset($_GET['callback']) && preg_match('/^[A-Za-z0-9_-]+$/', $_GET['callback'])) {
        $callback = $_GET['callback'];
    }
    if (!empty($callback)) {
        header('Content-Type: text/javascript; charset="utf-8"');
        echo $callback . '(' . json_encode($nagios_status) . ');';
    } else {
        header('Content-Type: application/json; charset="utf-8"');
        echo json_encode($nagios_status);
    }
    exit;
} else {
    header('Cache-Control: no-cache');
    header('Content-Type: text/html; charset="utf-8"');

    if ($refresh > 0) {
        header("Refresh: " . $refresh);
    }

    echo "<!doctype html>\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "  <title>" . htmlspecialchars($page_title) . "</title>\n";
    echo "  <meta http-equiv=\"content-type\" content=\"text/html;charset=utf-8\" />\n";
    echo '  <meta name="viewport" content="width=device-width,initial-scale=1">'."\n";
    echo '  <link href="//fonts.googleapis.com/css?family=Open+Sans:400,700" rel="stylesheet" type="text/css">'."\n";
    echo '  <link href="//fonts.googleapis.com/css?family=Varela+Round" rel="stylesheet" type="text/css">'."\n";
    echo "  <link rel=\"stylesheet\" type=\"text/css\" media=\"screen\" href=\"default.css\" />\n";
    if (is_readable("custom.css")) {
        echo "  <link rel=\"stylesheet\" type=\"text/css\" media=\"screen\" href=\"custom.css\" />\n";
    }
    echo "</head>\n";

    echo "<body>\n";
    echo '<div id="content">'."\n";
    echo "<h1 class='page-title'>" . htmlspecialchars($page_title). "</h1>\n";

    // Hosts section
    if ($nagios_status['status'] != 'ok') {
        echo "<h2 class='state down'>" . htmlspecialchars($nagios_status['message']) . "</h2>\n";
    } else {
        $counter = $nagios_status['data']['counter'];
        $states  = $nagios_status['data']['states'];

        sectionHeader('hosts', $counter);

        if ($counter['hosts']['down']) {
            print("<h2 class='state down'>There are hosts in trouble</h2>\n");
            hostsTable($nagios, $states['hosts']);
        } else {
            echo "<h2 class='state up'>All monitored hosts are UP</h2>\n";
        }

        foreach(array('unreachable', 'acknowledged', 'pending', 'notification') as $type) {
            if ($counter['hosts'][$type]) {
                print(sprintf('<div class="subhosts %s"><b>%s:</b> %s</div>', $type, ucfirst($type), implode(', ', $states['hosts'][$type])));
            }
        }

        // Services section
        sectionHeader('services', $counter);

        if ($counter['services']['warning'] || $counter['services']['critical'] || $counter['services']['unknown']) {
            $servicesStatus = 'unknown';
            if ($counter['services']['critical']) {
                $servicesStatus = 'critical';
            } elseif ($counter['services']['warning']) {
                $servicesStatus = 'warning';
            }
            print("<h2 class='state " . $servicesStatus . "'>There are services in trouble</h2>\n");
            print('<h3 class="serviceStateName">Critical / Warning / Unknown</h3>'."\n");
            servicesTable($nagios, $states['services'], array('critical', 'warning', 'unknown'));
        } else {
            print("<h2 class='state up'>All monitored services are OK</h2>\n");
        }

        foreach(array('acknowledged', 'notification', 'pending') as $type) {
            if ($counter['services'][$type]) {
                print(sprintf('<h3 class="serviceStateName">%s</h3>'."\n", ucfirst($type)));
                servicesTable($nagios, $states['services'], array($type), $type);
            }
        }
    }

    print("</div>\n");
    print("</body>\n");
    print("</html>\n");
}
?>
