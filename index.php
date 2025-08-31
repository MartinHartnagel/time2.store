<?php
// configure in common.php 

require_once __DIR__ . "/common.php";

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

    debugLog($customer . " content-len " . strlen($content) . " content " . $content);
    $offset = strlen("event: " . $eventType[1] . "\ndata: ");
    debugLog($customer . " offset " . $offset . " content " . substr($content, $offset, null));
    $json = substr($content, $offset);
    debugLog($customer . " json " . $json);
    $input = json_decode($json, true);
    if (json_last_error() > 0) {
        header("status: 400");
        echo ("input json_error: " . json_last_error() . "\nmessage: " . json_last_error_msg() . "\n");
        exit();
    }

    debugLog($customer . " eventType " . $eventType[1]);
    if (in_array($eventType[1], ["layout", "event", "invoice"])) {
        if (in_array($eventType[1], ["layout", "invoice"])) {
            $input = [$input];
        }
        debugLog($customer . " input " . var_export($input, true));
        foreach ($input as $i) {

            if ($eventType[1] == 'invoice') {
                if (isset($i['deleteAllInvoices'])) {
                    $db->deleteAllInvoices();
                    debugLog($customer . " deleteAllInvoices completed");
                } else if (isset($i['deleteInvoice'])) {
                    $db->deleteInvoice($i['deleteInvoice']);
                    debugLog($customer . " deleteInvoice completed");
                } else {
                    $db->storeInvoice($i['invoice']['invoiceNumber'], $i);
                    debugLog($customer . " storeInvoice completed");
                }
            } else if (isset($i["n"])) {
                //event
                $db->storeEvent($i["s"], str_replace("\n", "", $i["n"]), $i["c"], $i["e"]);
                debugLog("storeEvent completed");
            } elseif (isset($i["i"])) {
                //info
                $db->storeInfo($i["s"], str_replace("\n", "", $i["i"]));
                debugLog("storeInfo completed");
            } elseif (isset($i["v"])) {
                // layout
                $layoutAt = isset($_REQUEST["a"])
                    ? preg_replace("/[^0-9]/", "", $_REQUEST["a"])
                    : floor(microtime(true) * 1000);
                $db->storeLayout($layoutAt, json_encode($i));
                debugLog("storeLayout completed");
            } else if (isset($i["s"])) {
                // stop event
                $db->storeEvent($i["s"], '', '', $i["s"]);
                debugLog("storeEvent completed");
            } else {
                header("status: 400");
                debugLog("unknown input " . var_export($i, true));
                exit();
            }
        }
        header("status: 204");

        if (function_exists("pcntl_fork")) {
            $fork = pcntl_fork();
            if ($fork == -1) {
                debugLog($customer . " fork failed");
            } elseif ($fork == 0) {
                debugLog($customer . " forked postSSE");

                postSSE($customer, $userId, "*", $content);
                debugLog($customer . " forked postSSE completed");
            }
        } else {
            postSSE($customer, $userId, "*", $content);
            debugLog("forked postSSE completed");
        }
    } elseif ($eventType[1] == "sync-check") {
        $db->loadLayoutAndChanged(time() * 1000, $layout, $layoutChanged);
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
            debugLog("sync-override " . count($overrides));
            $c = json_encode($overrides);
            if (json_last_error() > 0) {
                header("status: 400");
                echo ("overrides json_error: " . json_last_error() . "\nmessage: " . json_last_error_msg() . "\n");
                exit();
            }

            $identifier = base_convert(random_int(100000000, 999999999), 10, 36);
            $checksum = hash("sha256", $c);
            $parts = str_split(
                base64_encode(rawurlencode($c)),
                PARTS_CHUNK
            );
            for ($i = 0; $i < count($parts); $i++) {
                debugLog("postSSE(" . "event: sync-part\ndata: " . $identifier . ":" . $i . "=" . $parts[$i] . ");");
                postSSE($customer, "store", $userId, "event: sync-part\ndata: " . $identifier . ":" . $i . "=" . $parts[$i]);
                usleep(500);
            }
            postSSE($customer, "store", $userId, "event: sync-override-complete\ndata: " . $identifier . ":" . count($parts) . "=" . $checksum);
        } else {
            debugLog("in sync");
            postSSE($customer, "store", $userId, "event: sync-info\ndata: in sync");

            if (rand(0, 100) == 42) {
                // cleanup
                debugLog("cleanup run");
                $db->cleanup();
            }
        }
        header("status: 204");
    }
} else if ($method == "GET") {
    $db->loadLayoutAndChanged(time() * 1000, $layout, $layoutChanged);
    $db->loadEventDays($days);
    $db->loadInvoiceChecksums($invoiceChecksums);
    $c = json_encode(['layout' => $layout, 'layoutChanged' => +$layoutChanged, 'eventDays' => $days, 'invoiceChecksums' => $invoiceChecksums]);
    header("status: 200");
    header('Content-Type: application/json;charset=utf-8;');
    echo ($c);
}
