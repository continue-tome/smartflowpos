<?php
$file = 'c:\composer\omegasecond\omega-pos\app\Http\Controllers\Api\smart2786643 (2).sql';
$content = file_get_contents($file);

preg_match('/INSERT INTO `payments` \([^)]+\) VALUES\s*(.*?);/s', $content, $matches);
$paymentsValues = $matches[1];
$paymentRows = explode("),\n(", trim($paymentsValues, "()\n"));

preg_match('/INSERT INTO `orders` \([^)]+\) VALUES\s*(.*?);/s', $content, $matches);
$ordersValues = $matches[1];
$orderRows = explode("),\n(", trim($ordersValues, "()\n"));

$orderTotals = [];
$orderStatuses = [];
foreach ($orderRows as $row) {
    $cols = str_getcsv($row, ",", "'");
    $id = trim($cols[0]);
    $orderTotals[$id] = (float)$cols[16];
    $orderStatuses[$id] = trim($cols[7], "' ");
}

$orderPayments = [];
foreach ($paymentRows as $row) {
    $row = trim($row);
    if (strpos($row, "'2026-05-01") === false) {
        continue;
    }
    $cols = str_getcsv($row, ",", "'");
    $amount = (float)$cols[5];
    $orderId = trim($cols[1]);
    
    if (!isset($orderPayments[$orderId])) {
        $orderPayments[$orderId] = 0;
    }
    $orderPayments[$orderId] += $amount;
}

foreach ($orderPayments as $orderId => $paidAmt) {
    $expectedTotal = $orderTotals[$orderId] ?? 0;
    $status = $orderStatuses[$orderId] ?? 'unknown';
    
    if ($paidAmt != $expectedTotal) {
        echo "Order $orderId ($status): Expected Total = $expectedTotal, But Payments = $paidAmt (Diff = " . ($paidAmt - $expectedTotal) . ")\n";
    }
}
