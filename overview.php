<?php

require_once __DIR__ . "/common.php";

if ($method == "GET") {
    $db->loadLayoutAndChanged(time() * 1000, $layout, $layoutChanged);
    $db->loadEventDays($days);
    $db->loadInvoiceChecksums($invoiceChecksums);
    $c = json_encode(['layout' => $layout, 'layoutChanged' => +$layoutChanged, 'eventDays' => $days, 'invoiceChecksums' => $invoiceChecksums]);
    header("status: 200");
    header('Content-Type: application/json;charset=utf-8;');
    echo ($c);
}
