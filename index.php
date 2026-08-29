<?php
// configure in config.php 

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
            $bid = postBag(substr($content, 1, -1)); // strip of [ and ]
            $content = "event: layout\ndata: " . $bid; 
        } else {
            $content = "event: event\ndata: " . $content;
        }
    }

    if (!preg_match("/^event: (.*)/", $content, $eventType)) {
        http_response_code(415);
        exit();
    }

    if (
        !in_array($eventType[1], [
            "layout", 
            "event", 
            "invoice", 
            "invoice-delete", 
            "invoice-delete-all", 
            "note", 
            "note-delete", 
            "note-delete-all", 
            "sync-check"
            ])
    ) {
        http_response_code(415);
        exit();
    }

    // debugLog($customer . " content-len " . strlen($content) . " content " . $content);
    $offset = strlen("event: " . $eventType[1] . "\ndata: ");
    // debugLog($customer . " offset " . $offset . " content " . substr($content, $offset, null));
    $data = substr($content, $offset);

    if (in_array($eventType[1], ["layout", "invoice", "note", "sync-check"])) { // bag contains json
        $bid = trim($data);
        $start = time();
        $json = null;
        do {
            $json = getBag($bid);
        } while(!$json && (time() - $start < BAG_RETRY));
        $input = json_decode($json, true);
    } else if (in_array($eventType[1], ["invoice-delete", "invoice-delete-all", "note-delete", "note-delete-all"])) { // data is text input
        $input = trim($data);
    } else { // data is json
        $json = trim($data);
        $input = json_decode($json, true);
    }
    
    if (json_last_error() > 0) {
        header("status: 400");
        echo ("input json_error: " . json_last_error() . "\nmessage: " . json_last_error_msg() . "\n");
        exit();
    }

    // debugLog($customer . " eventType " . $eventType[1]);
    if ($eventType[1] == "sync-check") {
        $db->loadLayoutAndChanged(time() * 1000, $layout, $layoutChanged);
        if (!isset($layout)) { // this db is empty, so trigger a full import
            debugLog($customer . " sync-import");
            postSSE($customer, "store", $userId, "event: sync-import\ndata: {}");
            header("status: 204");
            exit();
        }
        $db->loadEventDays($days);
        $db->loadInvoiceChecksums($invoiceChecksums);
        $db->loadNoteChecksums($noteChecksums);

        $vals = [];
        $checksums = [];
        foreach ($days as $day) {
            $db->loadEventsOnDay($day, $c);
            if (strlen(trim($c)) > 0) {
                $vals["events_" . $day] = $c;
                $checksums["events_" . $day] = hash("sha256", $c);
            }
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
        foreach ($noteChecksums as $noteKey => $noteChecksum) {
            $checksums[$noteKey] = $noteChecksum;
        }

        $keys = array_unique(
            array_merge(array_keys($input), array_keys($checksums))
        );
        rsort($keys);

        $overrides = [];
        $estimatedSize = 0;
        foreach ($keys as $k) {
            if (isset($input[$k]) && !isset($checksums[$k])) {
                if (strpos($k, 'invoice_') !== 0 && strpos($k, 'note_') !== 0) {
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
                } elseif (strpos($k, 'note_') === 0) {
                    $noteJson = $db->loadNote(substr($k, strlen('note_')));
                    $overrides[$k] = $noteJson;
                } else {
                    $overrides[$k] = $vals[$k];
                }
            } elseif (
                (
                    $k !== 'layout-changed' &&
                    isset($input[$k]) &&
                    isset($checksums[$k]) &&
                    $input[$k] !== $checksums[$k]
                ) || (
                    $k == 'layout-changed' &&
                    isset($input[$k]) &&
                    isset($checksums[$k]) &&
                    $input[$k] < $checksums[$k]
                )
            ) {
                debugLog($customer . " checksum-mismatch " . $k);
                if (strpos($k, 'invoice_') === 0) {
                    $invoiceJson = $db->loadInvoiceValue($k);
                    $overrides[$k] = $invoiceJson;
                    $invoice = json_decode($invoiceJson, true);
                    $overrides[$invoice['main']] = $db->loadInvoiceValue($invoice['main']);
                    $overrides[$invoice['footer']] = $db->loadInvoiceValue($invoice['footer']);
                    foreach ($invoice['assets'] as $key) {
                        $overrides[$key] = $db->loadInvoiceValue($key);
                    }
                } elseif (strpos($k, 'note_') === 0) {
                    $noteJson = $db->loadNote(substr($k, strlen('note_')));
                    $overrides[$k] = $noteJson;
                } else {
                    $overrides[$k] = $vals[$k];
                }
            }
            if (isset($overrides[$k]) && $overrides[$k] !== null) {
                $estimatedSize += strlen($overrides[$k]);
                if ($estimatedSize > SYNC_OVERRIDE_SEND_INIT) {
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
            debugLog($customer . " sync-override " . count($overrides));
            postSSE($customer, "store", '*', "event: sync-info\ndata: sync-override " . count($overrides));
            $c = json_encode($overrides);
            if (json_last_error() > 0) {
                header("status: 400");
                echo ("overrides json_error: " . json_last_error() . "\nmessage: " . json_last_error_msg() . "\n");
                exit();
            }

            $bid = postBag($c);
            postSSE($customer, "store", $userId, "event: sync-override\ndata: " . $bid);
        } else {
            debugLog($customer . " in sync");
            postSSE($customer, "store", '*', "event: sync-info\ndata: in sync");

            if (rand(0, 100) == 42) {
                // cleanup
                debugLog($customer . " cleanup run");
                $db->cleanup();
            }
        }
        header("status: 204");
    } else {
        if (!in_array($eventType[1], ["event"])) { // check id multi allowed, elsewise wrap to process in loop below
            $input = [$input];
        }
        // debugLog($customer . " input " . var_export($input, true));
        foreach ($input as $i) {
            switch($eventType[1]) {
                case 'invoice':
                    $db->storeInvoice($i['invoice']['invoiceNumber'], $i);
                    postSSE($customer, "store", '*', "event: sync-info\ndata: storeInvoice " . $i['invoice']['invoiceNumber'] . " completed");
                    break;
                case 'invoice-delete':
                    $db->deleteInvoice($i);
                    postSSE($customer, "store", '*', "event: sync-info\ndata: deleteInvoice $i completed");
                    break;
                case 'invoice-delete-all':
                    $db->deleteAllInvoices();
                    postSSE($customer, "store", '*', "event: sync-info\ndata: deleteAllInvoices completed");
                    break;
                case 'note':
                    $db->storeNote($i['id'], $i);
                    postSSE($customer, "store", '*', "event: sync-info\ndata: storeNote " . $i['id'] . " completed");
                    break;
                case 'note-delete':
                    $db->deleteNote($i);
                    postSSE($customer, "store", '*', "event: sync-info\ndata: deleteNote $i completed");
                    break;
                case 'note-delete-all':
                    $db->deleteAllNotes();
                    postSSE($customer, "store", '*', "event: sync-info\ndata: deleteAllNotes completed");
                    break;
                case 'event':
                    if (isset($i["n"])) {
                        //event
                        $db->storeEvent($i["s"], str_replace("\n", "", $i["n"]), $i["c"], $i["e"] ?? null);
                        debugLog($customer . " storeEvent completed");
                        postSSE($customer, "store", '*', "event: sync-info\ndata: storeEvent completed");
                    } elseif (isset($i["i"])) {
                        //info
                        $db->storeInfo($i["s"], str_replace("\n", "", $i["i"]));
                        debugLog($customer . " storeInfo completed");
                        postSSE($customer, "store", '*', "event: sync-info\ndata: storeInfo completed");
                    } else if (isset($i["s"])) {
                        // stop event
                        $db->storeEvent($i["s"], '', '', $i["s"]);
                        debugLog($customer . " storeEvent completed");
                        postSSE($customer, "store", '*', "event: sync-info\ndata: storeEvent completed");
                    }
                    break;
                case 'layout':
                    $layoutAt = isset($_REQUEST["a"])
                        ? preg_replace("/[^0-9]/", "", $_REQUEST["a"])
                        : floor(microtime(true) * 1000);
                    $db->storeLayout($layoutAt, json_encode($i));
                    debugLog($customer . " storeLayout completed");
                    postSSE($customer, "store", '*', "event: sync-info\ndata: storeLayout completed");
                    break;
                default:
                    header("status: 400");
                    debugLog($customer . " unknown input " . var_export($i, true));
                    exit();
            }
        }
        header("status: 204");

        if (function_exists("pcntl_fork")) {
            $fork = pcntl_fork();
            if ($fork == -1) {
                // debugLog($customer . " fork failed");
            } elseif ($fork == 0) {
                // debugLog($customer . " forked postSSE");

                postSSE($customer, $userId, "*", $content);
                // debugLog($customer . " forked postSSE completed");
            }
        } else {
            postSSE($customer, $userId, "*", $content);
            // debugLog("postSSE completed");
        }
    }
}
