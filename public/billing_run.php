<?php
require __DIR__ . '/../lib/bootstrap.php';

$period = $_POST['period'] ?? null;
$query = valid_billing_period($period) ? '?' . http_build_query(['period' => $period]) : '';
header('Location: billing.php' . $query, true, 303);
