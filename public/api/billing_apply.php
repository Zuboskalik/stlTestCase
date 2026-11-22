<?php
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_out(['error' => 'Используйте метод POST.'], 405);
}

$period = $_POST['period'] ?? null;
if (!valid_billing_period($period)) {
    json_out(['error' => 'Укажите месяц в формате ГГГГ-ММ.'], 400);
}

json_out(billing_apply($period));
