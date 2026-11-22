$(function () {
    var $table = $('#trip-split');
    var $status = $('#split-status');
    var basisMessages = {
        weight_kg: 'Объём грузов равен нулю или отсутствует. Стоимость распределена по весу.',
        equal: 'Общий объём и вес равны нулю или отсутствуют. Стоимость распределена поровну.',
        none: 'В рейсе нет грузов. Распределение отсутствует.'
    };

    $.getJSON('api/trip_split.php', { trip_id: $table.data('tripId') })
        .done(function (data) {
            var $body = $table.find('tbody');
            data.clients.forEach(function (client) {
                $('<tr>')
                    .append($('<td>').text(client.name))
                    .append($('<td class="r">').text(client.cargo_count))
                    .append($('<td class="r">').text(num(client.weight_kg)))
                    .append($('<td class="r">').text(num(client.volume_m3)))
                    .append($('<td class="r">').text(num(client.share * 100)))
                    .append($('<td class="r">').text(money(client.amount)))
                    .appendTo($body);
            });
            $('#split-total').text(money(data.total_allocated) + ' / ' + money(data.cost));
            $status.text(basisMessages[data.basis] || '').prop('hidden', !basisMessages[data.basis]);
            $table.prop('hidden', false);
        })
        .fail(function () {
            $status.text('Не удалось загрузить распределение стоимости.');
        });
});
