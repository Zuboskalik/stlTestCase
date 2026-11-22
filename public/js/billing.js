$(function () {
    var $period = $('#billing-period');
    var $show = $('#billing-show');
    var $apply = $('#billing-apply');
    var $table = $('#billing-results');
    var $status = $('#billing-status');
    var previewPeriod = null;

    function refreshControls(busy) {
        $period.prop('disabled', busy);
        $show.prop('disabled', busy);
        $apply.prop('disabled', busy || previewPeriod === null);
    }

    function render(data, applied) {
        var $body = $table.find('tbody').empty();
        var labels = applied
            ? { created: 'Создан', updated: 'Обновлён', unchanged: 'Без изменений' }
            : { created: 'Будет создан', updated: 'Будет обновлён', unchanged: 'Без изменений' };

        data.clients.forEach(function (client) {
            $('<tr>')
                .append($('<td>').text(client.name))
                .append($('<td class="r">').text(money(client.before)))
                .append($('<td class="r">').text(money(client.after)))
                .append($('<td class="r">').text(money(client.diff)))
                .append($('<td>').text(labels[client.action]))
                .appendTo($body);
        });
        $('#billing-before').text(money(data.total_before));
        $('#billing-after').text(money(data.total_after));
        $('#billing-diff').text(money(data.total_diff));
        $table.prop('hidden', false);
    }

    $period.on('input change', function () {
        previewPeriod = null;
        $table.prop('hidden', true);
        $status.empty();
        refreshControls(false);
    });

    $('#billing-form').on('submit', function (event) {
        event.preventDefault();
        var period = $period.val();
        previewPeriod = null;
        $table.prop('hidden', true);
        $status.text('Загрузка предпросмотра...');
        refreshControls(true);
        $.getJSON('api/billing_preview.php', { period: period })
            .done(function (data) {
                if (data.created + data.updated > 0) {
                    previewPeriod = data.period;
                }
                render(data, false);
                $status.text(data.clients.length ? 'Предпросмотр за ' + data.period + '.' : 'За этот месяц счетов и рейсов с грузами нет.');
            })
            .fail(function (xhr) {
                $status.text(xhr.responseJSON?.error || 'Не удалось получить предпросмотр.');
            })
            .always(function () {
                refreshControls(false);
            });
    });

    $apply.on('click', function () {
        if (previewPeriod !== $period.val() || !window.confirm('Пересчитать счета за ' + previewPeriod + '?')) {
            return;
        }

        $status.text('Применение изменений...');
        refreshControls(true);
        $.post('api/billing_apply.php', { period: previewPeriod }, null, 'json')
            .done(function (data) {
                previewPeriod = null;
                render(data, true);
                $status.text('Применено за ' + data.period + '. Создано ' + data.created +
                    ', обновлено ' + data.updated + ', без изменений ' + data.unchanged + '.');
            })
            .fail(function (xhr) {
                $status.text(xhr.responseJSON?.error || 'Не удалось применить изменения.');
            })
            .always(function () {
                refreshControls(false);
            });
    });
});
