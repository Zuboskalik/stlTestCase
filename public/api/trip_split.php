<?php
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_out(['error' => 'Используйте метод GET.'], 405);
}

$tripId = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT);
$query = db()->prepare('SELECT id, number, cost FROM trips WHERE id = ?');
$query->execute([$tripId === false ? null : $tripId]);
$trip = $query->fetch();
if (!$trip) {
    json_out(['error' => 'Рейс не найден.'], 404);
}

json_out(trip_split($trip));
