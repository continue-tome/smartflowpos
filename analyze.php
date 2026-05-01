<?php
$file = 'c:\composer\omegasecond\omega-pos\app\Http\Controllers\Api\smart2786643 (2).sql';
$content = file_get_contents($file);

preg_match('/INSERT INTO `orders` \([^)]+\) VALUES\s*(.*?);/s', $content, $matches);
if (!$matches) {
    echo "Orders insert not found\n";
    exit;
}

$values = $matches[1];
$rows = explode("),\n(", trim($values, "()\n"));

$totalAllOrders = 0;
$totalPaid = 0;
$totalUnpaid = 0;

foreach ($rows as $row) {
    $row = trim($row);
    if (strpos($row, "'2026-05-01") === false) {
        continue;
    }

    $cols = str_getcsv($row, ",", "'");
    
    // cols:
    // 0: id
    // 6: type
    // 7: status
    // 12: subtotal
    // 13: discount
    // 16: total
    // 21: created_at
    
    $id = $cols[0];
    $status = $cols[7];
    $total = (float)$cols[16];
    $subtotal = (float)$cols[12];
    
    if (strpos($cols[21] ?? $cols[20] ?? '', '2026-05-01') !== false) {
        $totalAllOrders += $total;
        if ($status === 'paid') {
            $totalPaid += $total;
        } else if (in_array($status, ['open', 'sent_to_kitchen', 'partially_served', 'served'])) {
            $totalUnpaid += $total;
        }
    }
}

echo "Total All Orders (created today): $totalAllOrders\n";
echo "Total Paid Orders: $totalPaid\n";
echo "Total Unpaid Orders: $totalUnpaid\n";
echo "Sum Paid+Unpaid: " . ($totalPaid + $totalUnpaid) . "\n";

