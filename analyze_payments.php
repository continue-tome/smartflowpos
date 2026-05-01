<?php
$file = 'c:\composer\omegasecond\omega-pos\app\Http\Controllers\Api\smart2786643 (2).sql';
$content = file_get_contents($file);

preg_match('/INSERT INTO `payments` \([^)]+\) VALUES\s*(.*?);/s', $content, $matches);
$paymentsValues = $matches[1];
$paymentRows = explode("),\n(", trim($paymentsValues, "()\n"));

preg_match('/INSERT INTO `orders` \([^)]+\) VALUES\s*(.*?);/s', $content, $matches);
$ordersValues = $matches[1];
$orderRows = explode("),\n(", trim($ordersValues, "()\n"));

$orderDates = [];
foreach ($orderRows as $row) {
    $cols = str_getcsv($row, ",", "'");
    $id = trim($cols[0]);
    $created_at = trim($cols[21] ?? $cols[20] ?? '', "' "); // Adjust index if needed based on table def
    $orderDates[$id] = substr($created_at, 0, 10);
}

$paymentsToday = 0;
$paymentsTodayForPastOrders = 0;

foreach ($paymentRows as $row) {
    $row = trim($row);
    if (strpos($row, "'2026-05-01") === false) {
        continue;
    }

    $cols = str_getcsv($row, ",", "'");
    $amount = (float)$cols[5];
    $orderId = trim($cols[1]);
    $created_at = trim($cols[11], "' ");
    
    if (strpos($created_at, '2026-05-01') === 0) {
        $paymentsToday += $amount;
        $orderDate = $orderDates[$orderId] ?? 'unknown';
        if ($orderDate !== '2026-05-01') {
            $paymentsTodayForPastOrders += $amount;
        }
    }
}

echo "Total Payments Today: $paymentsToday\n";
echo "Payments Today for Orders created BEFORE today: $paymentsTodayForPastOrders\n";

