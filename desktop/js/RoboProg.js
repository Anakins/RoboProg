/* JS écrin du plugin RoboProg (page équipement : Général + Commandes) */

/* ------------------------------------------------------------------ */
/* Mécanisme OFFICIEL Jeedom : printEqLogic(_eqLogic) est appelée      */
/* automatiquement par le core (plugin.template.js) à CHAQUE chargement */
/* des données d'un équipement (clic sur une carte, changement d'onglet,*/
/* navigation directe par URL/hash...).                                */
/* ------------------------------------------------------------------ */
function printEqLogic(_eqLogic) {
    if (typeof _eqLogic === 'undefined') {
        _eqLogic = {};
    }

    $('#table_cmd tbody tr.cmd').remove();
    if (_eqLogic.cmd) {
        for (var i in _eqLogic.cmd) {
            RoboProg_addCmdToTable(_eqLogic.cmd[i]);
        }
    }
}

function RoboProg_addCmdToTable(_cmd) {
    if (typeof _cmd === 'undefined') {
        _cmd = {};
    }
    var $tr = $('#table_cmd .cmdTemplate').clone();
    $tr.removeClass('cmdTemplate').addClass('cmd').show();
    if (_cmd.type != 'action') {
        $tr.find('.bt_testCmd').remove();
    }
    $('#table_cmd tbody').append($tr);
    var $lastRow = $('#table_cmd tbody tr:last');
    $lastRow.setValues(_cmd, '.cmdAttr');
    $lastRow.find('.cmd_state').text((_cmd.value !== undefined && _cmd.value !== null) ? _cmd.value : '');
}

$('#commandtab').on('click', '.bt_testCmd', function () {
    var $tr = $(this).closest('tr');
    var cmd_id = $tr.find('.cmdAttr[data-l1key="id"]').text();
    $.ajax({
        type: 'POST',
        url: 'core/ajax/cmd.ajax.php',
        data: { action: 'test', id: cmd_id },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $.fn.showAlert({ message: data.result, level: 'danger' });
                return;
            }
            $.fn.showAlert({ message: '{{Commande exécutée}}', level: 'success' });
        }
    });
});
