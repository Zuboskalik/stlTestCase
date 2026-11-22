<?php

function trip_cargo_by_client($tripId)
{
    $query = db()->prepare("SELECT c.client_id, cl.name, COUNT(*) AS cargo_count,
            SUM(c.weight_kg) AS weight_kg, SUM(COALESCE(c.volume_m3, 0)) AS volume_m3
        FROM cargo c
        JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = ?
        GROUP BY c.client_id, cl.name
        ORDER BY c.client_id");
    $query->execute([$tripId]);

    return $query->fetchAll();
}

function to_cents($amount)
{
    return (int)round($amount * 100);
}

function decimal_money($cents)
{
    return number_format($cents / 100, 2, '.', '');
}

function trip_split($trip)
{
    $clients = trip_cargo_by_client($trip['id']);
    $cost = to_cents($trip['cost']);
    $basis = 'volume_m3';
    $total = array_sum(array_column($clients, $basis));

    if ($total <= 0) {
        $basis = 'weight_kg';
        $total = array_sum(array_column($clients, $basis));
    }
    if ($total <= 0) {
        $basis = $clients ? 'equal' : 'none';
        $total = count($clients);
    }

    $remainders = [];
    $amounts = [];
    $unallocated = abs($cost);
    foreach ($clients as $index => &$client) {
        $client['share'] = ($basis === 'equal' ? 1 : $client[$basis]) / $total;
        $exact = abs($cost) * $client['share'];
        $amounts[$index] = (int)floor($exact);
        $remainders[$index] = $exact - $amounts[$index];
        $unallocated -= $amounts[$index];
    }
    unset($client);

    // Дополнительные копейки получают наибольшие остатки, при равенстве - меньший ID клиента.
    uksort($remainders, static function ($a, $b) use ($remainders) {
        return ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b);
    });
    foreach (array_slice(array_keys($remainders), 0, $unallocated) as $index) {
        $amounts[$index]++;
    }

    $sign = $cost < 0 ? -1 : 1;
    foreach ($clients as $index => &$client) {
        $client['amount'] = decimal_money($sign * $amounts[$index]);
    }
    unset($client);

    return [
        'trip_id' => $trip['id'],
        'number' => $trip['number'],
        'cost' => decimal_money($cost),
        'basis' => $basis,
        'clients' => $clients,
        'total_allocated' => decimal_money($sign * array_sum($amounts)),
    ];
}

function valid_billing_period($period)
{
    return is_string($period) && preg_match('/^(?!0000)[0-9]{4}-(0[1-9]|1[0-2])$/D', $period);
}

function billing_plan($period)
{
    $db = db();
    $query = $db->prepare("SELECT id, number, cost FROM trips
        WHERE departure_date LIKE ? AND status <> 'cancelled'
        ORDER BY departure_date, id");
    $query->execute([$period . '%']);

    $plannedLines = [];
    foreach ($query->fetchAll() as $trip) {
        foreach (trip_split($trip)['clients'] as $client) {
            $plannedLines[$client['client_id']][$trip['id']] = to_cents($client['amount']);
        }
    }

    $query = $db->prepare("SELECT id, client_id, total FROM invoices WHERE period = ?");
    $query->execute([$period]);
    $invoices = array_column($query->fetchAll(), null, 'client_id');

    $query = $db->prepare("SELECT l.invoice_id, l.trip_id, l.amount FROM invoice_lines l
        JOIN invoices i ON i.id = l.invoice_id WHERE i.period = ?");
    $query->execute([$period]);
    $existingLines = [];
    foreach ($query->fetchAll() as $line) {
        $existingLines[$line['invoice_id']][$line['trip_id']][] = to_cents($line['amount']);
    }

    $plan = [];
    foreach (get_clients() as $client) {
        $clientId = $client['id'];
        $invoice = $invoices[$clientId] ?? null;
        $lines = $plannedLines[$clientId] ?? [];
        if (!$invoice && !$lines) {
            continue;
        }

        $before = to_cents($invoice['total'] ?? 0);
        $after = array_sum($lines);
        $stored = $existingLines[$invoice['id'] ?? 0] ?? [];
        $expected = array_map(static fn($amount) => [$amount], $lines);
        $action = !$invoice ? 'created' : ($before !== $after || $stored != $expected ? 'updated' : 'unchanged');
        $plan[] = [
            'client_id' => $clientId,
            'name' => $client['name'],
            'invoice_id' => $invoice['id'] ?? null,
            'before' => $before,
            'after' => $after,
            'action' => $action,
            'lines' => $lines,
        ];
    }

    return $plan;
}

function billing_response($period, $plan)
{
    $totalBefore = array_sum(array_column($plan, 'before'));
    $totalAfter = array_sum(array_column($plan, 'after'));
    $response = [
        'period' => $period,
        'clients' => [],
        'total_before' => decimal_money($totalBefore),
        'total_after' => decimal_money($totalAfter),
        'total_diff' => decimal_money($totalAfter - $totalBefore),
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
    ];
    foreach ($plan as $entry) {
        $response[$entry['action']]++;
        unset($entry['lines']);
        $entry['diff'] = decimal_money($entry['after'] - $entry['before']);
        $entry['before'] = decimal_money($entry['before']);
        $entry['after'] = decimal_money($entry['after']);
        $response['clients'][] = $entry;
    }
    return $response;
}

function billing_preview($period)
{
    $db = db();
    $db->beginTransaction();
    try {
        $plan = billing_plan($period);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }

    return billing_response($period, $plan);
}

function billing_apply($period)
{
    $db = db();
    // Блокировка записи до чтения плана защищает от параллельного создания счетов.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $plan = billing_plan($period);
        $insert = $db->prepare("INSERT INTO invoices (client_id, period, total, created_at)
            VALUES (?, ?, ?, datetime('now', 'localtime'))");
        $update = $db->prepare('UPDATE invoices SET total = ? WHERE id = ?');
        $deleteLines = $db->prepare('DELETE FROM invoice_lines WHERE invoice_id = ?');
        $insertLine = $db->prepare('INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES (?, ?, ?)');

        foreach ($plan as &$entry) {
            if ($entry['action'] === 'unchanged') {
                continue;
            }

            $total = decimal_money($entry['after']);
            if ($entry['action'] === 'created') {
                $insert->execute([$entry['client_id'], $period, $total]);
                $entry['invoice_id'] = (int)$db->lastInsertId();
            } else {
                $update->execute([$total, $entry['invoice_id']]);
                $deleteLines->execute([$entry['invoice_id']]);
            }
            foreach ($entry['lines'] as $tripId => $cents) {
                $insertLine->execute([$entry['invoice_id'], $tripId, decimal_money($cents)]);
            }
        }
        unset($entry);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }

    return billing_response($period, $plan);
}
