<?php
define("DB_TYPE", "sqlite"); // or mysql, configure below

/*
-- mysql create schema, replace "test" with your customer/topic and execute on mysql instance:

CREATE TABLE IF NOT EXISTS test_LAYOUT (time bigint NOT NULL UNIQUE, value text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);
CREATE TABLE IF NOT EXISTS test_EVENT (time bigint NOT NULL UNIQUE, name varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, color varchar(7) NOT NULL, end bigint);
CREATE TABLE IF NOT EXISTS test_INFO (time bigint NOT NULL UNIQUE, info text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);
CREATE TABLE IF NOT EXISTS test_INVOICE (`key` varchar(256) NOT NULL UNIQUE, `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);
*/

/* mysql configure connection:

define("DB_HOST", "127.0.0.1");
define("DB_PORT", "3306");
define("DB_NAME", "db123");
define("DB_USER", "user");
define("DB_PASSWORD", "12345");

// end of mysql configure connection */

define("PARTS_CHUNK", 3900);
define("SYNC_OVERRIDE_SEND_IMIT", 20000);

define("DEBUG", false);
if (DEBUG) {
    $log = fopen(__DIR__ . "/debug.log", "a");
}

$allowed = ['https://time2.emphasize.de', 'https://time2.dev.emphasize.de', 'http://localhost:3000'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? preg_replace("/[^A-Za-z0-9:\/\.-_]/", '', $_SERVER['HTTP_ORIGIN']) : null;
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($_REQUEST["topic"])) {
    exit();
}
$topic = preg_replace("/[^A-Za-z0-9 \\-]/", '', $_REQUEST['topic'] ?? 'test');

if (isset($origin) && in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method == 'OPTIONS') {
    exit();
}

$customer = preg_replace("/[^A-Za-z0-9]/", "", $_REQUEST["topic"]);
if (strlen($customer) == 0) {
    exit();
}

define("SUSPEND_SECONDS", 15);

$start = time();
$accepted = false;

$method = $_SERVER["REQUEST_METHOD"];

if ($method == "OPTIONS") {
    exit();
}

require_once __DIR__ . "/utils.php";

if (DB_TYPE == 'mysql') {
    require_once __DIR__ . "/MysqlDB.php";
    $db = new MysqlDB($customer);
} else if (DB_TYPE == 'sqlite') {
    require_once __DIR__ . "/SqliteDB.php";
    $db = new SqliteDB($customer);
} else {
    exit();
}

$userId = isset($_REQUEST["u"])
    ? preg_replace("/[^A-Za-z0-9]/", "", $_REQUEST["u"])
    : "extern";

if ($method == "POST") {
    $content = file_get_contents("php://input");
    $content = str_replace(
        ["%CURRENT_TIMESTAMP%"],
        [floor(microtime(true) * 1000)],
        $content
    );

    // compatibility for curl posts
    if (substr($content, 0, 7) != "event: ") {
        if (strpos($content, '"r"') !== false) {
            $content = "event: layout\ndata: " . substr($content, 1, -1); // strip of [ and ]
        } else {
            $content = "event: event\ndata: " . $content;
        }
    }

    if (!preg_match("/^event: (.*)/", $content, $eventType)) {
        exit();
    }

    if (
        !in_array($eventType[1], ["layout", "event", "invoice", "sync-check"])
    ) {
        exit();
    }

    if (DEBUG) {
        fwrite(
            $log,
            date("c") .
                " " .
                $customer .
                " content-len " .
                strlen($content) .
                " content " .
                $content .
                "\n"
        );
    }

    $offset = strlen("event: " . $eventType[1] . "\ndata: ");
    if (DEBUG) {
        fwrite(
            $log,
            date("c") .
                " " .
                $customer .
                " offset " .
                $offset .
                " content " .
                substr($content, $offset, null) .
                "\n"
        );
    }
    $json = substr($content, $offset);
    if (DEBUG) {
        fwrite($log, date("c") . " " . $customer . " json " . $json . "\n");
    }
    $input = json_decode($json, true);
    if (json_last_error() > 0) {
        header("status: 400");
        echo "input json_error: " .
            json_last_error() .
            "\nmessage: " .
            json_last_error_msg() .
            "\n";
        exit();
    }

    if (DEBUG) {
        fwrite(
            $log,
            date("c") . " " . $customer . " eventType " . $eventType[1] . "\n"
        );
    }
    if (in_array($eventType[1], ["layout", "event", "invoice"])) {
        if (in_array($eventType[1], ["layout", "invoice"])) {
            $input = [$input];
        }
        if (DEBUG) {
            fwrite(
                $log,
                date("c") .
                    " " .
                    $customer .
                    " input " .
                    var_export($input, true) .
                    "\n"
            );
        }

        foreach ($input as $i) {

            if ($eventType[1] == 'invoice') {
                if (isset($i['deleteAllInvoices'])) {
                    $db->deleteAllInvoices();
                    if (DEBUG) {
                        fwrite(
                            $log,
                            date("c") . " " . $customer . " deleteAllInvoices completed\n"
                        );
                    }
                } else if (isset($i['deleteInvoice'])) {
                    $db->deleteInvoice($i['deleteInvoice']);
                    if (DEBUG) {
                        fwrite(
                            $log,
                            date("c") . " " . $customer . " deleteInvoice completed\n"
                        );
                    }
                } else {
                    $db->storeInvoice($i['invoice']['invoiceNumber'], $i);
                    if (DEBUG) {
                        fwrite(
                            $log,
                            date("c") . " " . $customer . " storeInvoice completed\n"
                        );
                    }
                }
            } else if (isset($i["n"])) {
                //event
                $db->storeEvent(
                    $i["s"],
                    str_replace("\n", "", $i["n"]),
                    $i["c"],
                    $i["e"]
                );
                if (DEBUG) {
                    fwrite(
                        $log,
                        date("c") . " " . $customer . " storeEvent completed\n"
                    );
                }
            } elseif (isset($i["i"])) {
                //info
                $db->storeInfo($i["s"], str_replace("\n", "", $i["i"]));
                if (DEBUG) {
                    fwrite(
                        $log,
                        date("c") . " " . $customer . " storeInfo completed\n"
                    );
                }
            } elseif (isset($i["v"])) {
                // layout
                $layoutAt = isset($_REQUEST["a"])
                    ? preg_replace("/[^0-9]/", "", $_REQUEST["a"])
                    : floor(microtime(true) * 1000);
                $db->storeLayout($layoutAt, json_encode($i));
                if (DEBUG) {
                    fwrite(
                        $log,
                        date("c") . " " . $customer . " storeLayout completed\n"
                    );
                }
            } else if (isset($i["s"])) {
                // stop event
                $db->storeEvent(
                    $i["s"],
                    '',
                    '',
                    $i["s"]
                );
                if (DEBUG) {
                    fwrite(
                        $log,
                        date("c") . " " . $customer . " storeEvent completed\n"
                    );
                }
            } else {
                header("status: 400");
                if (DEBUG) {
                    fwrite(
                        $log,
                        date("c") . " " . $customer . " unknown input " . var_export($i, true) . "\n"
                    );
                }
                exit();
            }
        }
        header("status: 204");
            
        $fork = pcntl_fork();
        if ($fork == -1) {
            if (DEBUG) {
                fwrite($log, date("c") . " " . $customer . " fork failed\n");
            }
        } elseif ($fork == 0) {
            if (DEBUG) {
                fwrite($log, date("c") . " " . $customer . " forked postSSE\n");
            }
            postSSE($customer, $userId, "*", $content);
            if (DEBUG) {
                fwrite(
                    $log,
                    date("c") . " " . $customer . " forked postSSE completed\n"
                );
            }
        }
    } elseif ($eventType[1] == "sync-check") {
        $db->loadLayoutAndChanged($layout, $layoutChanged);
        $db->loadEventDays($days);
        $db->loadInvoiceChecksums($invoiceChecksums);

        $vals = [];
        $checksums = [];
        foreach ($days as $day) {
            $db->loadEventsOnDay($day, $c);
            $vals["events_" . $day] = $c;
            $checksums["events_" . $day] = hash("sha256", $c);
        }
        if ($layoutChanged != null) {
            $checksums["layout-changed"] = $layoutChanged;
            $vals["layout-changed"] = $layoutChanged;
        }
        if ($layout != null) {
            $checksums["layout"] = hash("sha256", $layout);
            $vals["layout"] = $layout;
        }
        foreach ($invoiceChecksums as $invoiceKey => $invoiceChecksum) {
            $checksums[$invoiceKey] = $invoiceChecksum;
        }

        $keys = array_unique(
            array_merge(array_keys($input), array_keys($checksums))
        );
        rsort($keys);

        $overrides = [];
        $estimatedSize = 0;
        foreach ($keys as $k) {
            if (isset($input[$k]) && !isset($checksums[$k])) {
                if (strpos($k, 'invoice_') !== 0) {
                    $overrides[$k] = "";
                }
            } elseif (!isset($input[$k]) && isset($checksums[$k])) {
                if (strpos($k, 'invoice_') === 0) {
                    $invoiceJson = $db->loadInvoiceValue($k);
                    $overrides[$k] = $invoiceJson;
                    $invoice = json_decode($invoiceJson, true);
                    $overrides[$invoice['main']] = $db->loadInvoiceValue($invoice['main']);
                    $overrides[$invoice['footer']] = $db->loadInvoiceValue($invoice['footer']);
                    foreach ($invoice['assets'] as $key) {
                        $overrides[$key] = $db->loadInvoiceValue($key);
                    }
                } else {
                    $overrides[$k] = $vals[$k];
                }
            } elseif (
                isset($input[$k]) &&
                isset($checksums[$k]) &&
                $input[$k] !== $checksums[$k]
            ) {
                if (strpos($k, 'invoice_') === 0) {
                    $invoiceJson = $db->loadInvoiceValue($k);
                    $overrides[$k] = $invoiceJson;
                    $invoice = json_decode($invoiceJson, true);
                    $overrides[$invoice['main']] = $db->loadInvoiceValue($invoice['main']);
                    $overrides[$invoice['footer']] = $db->loadInvoiceValue($invoice['footer']);
                    foreach ($invoice['assets'] as $key) {
                        $overrides[$key] = $db->loadInvoiceValue($key);
                    }
                } else {
                    $overrides[$k] = $vals[$k];
                }
            }
            if (isset($overrides[$k]) && $overrides[$k] !== null) {
                $estimatedSize += strlen($overrides[$k]);
                if ($estimatedSize > SYNC_OVERRIDE_SEND_IMIT) {
                    break;
                }
            }
        }
        if (
            !isset($overrides["layout"]) &&
            isset($overrides["layout-changed"])
        ) {
            unset($overrides["layout-changed"]);
        }
        if (count($overrides) > 0) {
            if (DEBUG) {
                fwrite(
                    $log,
                    date("c") .
                        " " .
                        $customer .
                        " sync-override " .
                        count($overrides) .
                        "\n"
                );
            }
            $c = json_encode($overrides);
            if (json_last_error() > 0) {
                header("status: 400");
                echo "overrides json_error: " .
                    json_last_error() .
                    "\nmessage: " .
                    json_last_error_msg() .
                    "\n";
                exit();
            }
        
            $identifier = base_convert(
                random_int(100000000, 999999999),
                10,
                36
            );
            $checksum = hash("sha256", $c);
            $parts = str_split(
                base64_encode(rawurlencode($c)),
                PARTS_CHUNK
            );
            for ($i = 0; $i < count($parts); $i++) {
                    if (DEBUG) {
                                fwrite($log, date("c") . " " . $customer . " postSSE(" . "event: sync-part\ndata: " .
                        $identifier .
                        ":" .
                        $i .
                        "=" .
                        $parts[$i] . ");\n");
                            }
                postSSE(
                    $customer,
                    "store",
                    $userId,
                    "event: sync-part\ndata: " .
                        $identifier .
                        ":" .
                        $i .
                        "=" .
                        $parts[$i]
                );
                usleep(500);
            }
            postSSE(
                $customer,
                "store",
                $userId,
                "event: sync-override-complete\ndata: " .
                    $identifier .
                    ":" .
                    count($parts) .
                    "=" .
                    $checksum
            );
        } else {
            if (DEBUG) {
                fwrite($log, date("c") . " " . $customer . " in sync\n");
            }
            postSSE(
                $customer,
                "store",
                $userId,
                "event: sync-info\ndata: in sync"
            );


            if (rand(0, 100) == 42) {
                // cleanup
                if (DEBUG) fwrite($log, date("c") . " cleanup run\n");
                $db->cleanup();
            }
        }
        header("status: 204");
    }
}
