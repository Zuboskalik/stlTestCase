<?php
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_out(['error' => 'Используйте метод GET.'], 405);
}

$period = $_GET['period'] ?? null;
if (!valid_billing_period($period)) {
    json_out(['error' => 'Укажите месяц в формате ГГГГ-ММ.'], 400);
}

header('Cache-Control: no-store');
json_out(billing_preview($period));
