<?php
require __DIR__ . '/../lib/bootstrap.php';

$period = $_GET['period'] ?? null;
if (!valid_billing_period($period)) {
    $period = db()->query("SELECT MAX(substr(departure_date, 1, 7)) FROM trips")->fetchColumn() ?: date('Y-m');
}

page_header('Счета за месяц');
?>
<h1>Счета за месяц</h1>

<form class="actions" id="billing-form">
    <label for="billing-period">Месяц</label>
    <input id="billing-period" type="month" name="period" value="<?= h($period) ?>" required>
    <button type="submit" id="billing-show">Показать</button>
    <button type="button" id="billing-apply" disabled>Применить</button>
</form>

<p id="billing-status" role="status"></p>
<div class="table-scroll">
<table class="grid" id="billing-results" hidden>
    <thead>
    <tr>
        <th scope="col">Клиент</th>
        <th scope="col" class="r">Было, р</th>
        <th scope="col" class="r">Стало, р</th>
        <th scope="col" class="r">Разница, р</th>
        <th scope="col">Изменение</th>
    </tr>
    </thead>
    <tbody></tbody>
    <tfoot>
    <tr class="total">
        <td>Итого</td>
        <td class="r" id="billing-before"></td>
        <td class="r" id="billing-after"></td>
        <td class="r" id="billing-diff"></td>
        <td></td>
    </tr>
    </tfoot>
</table>
</div>
<script src="js/billing.js"></script>
<?php page_footer();
