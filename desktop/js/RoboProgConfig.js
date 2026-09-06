/* JS de l'onglet Programmation (RoboProg). Fichier VOLONTAIREMENT      */
/* séparé de RoboProg.js (JS écrin).                                   */

var RoboProg_currentEqLogicId = null;

/* ------------------------------------------------------------------ */
/* Greffe sur printEqLogic() (déjà défini dans RoboProg.js) sans le     */
/* modifier : on l'enveloppe pour ajouter le chargement de la          */
/* programmation à chaque affichage d'un équipement.                   */
/* ------------------------------------------------------------------ */
(function () {
    var original = (typeof printEqLogic === 'function') ? printEqLogic : function () {};
    printEqLogic = function (_eqLogic) {
        original(_eqLogic);
        if (_eqLogic && _eqLogic.id) {
            RoboProg_currentEqLogicId = _eqLogic.id;
            RoboProg_load(_eqLogic.id);
        }
    };
})();

function RoboProg_eqId() {
    // Filet de sécurité : si le hook printEqLogic n'a pas encore tourné
    // (ou a été appelé sans id), on retombe sur le champ natif Jeedom qui
    // porte toujours l'id réel de l'équipement affiché.
    return RoboProg_currentEqLogicId || $('.eqLogicAttr[data-l1key="id"]').val();
}

/* ------------------------------------------------------------------ */
/* Chargement du formulaire depuis la config existante                 */
/* ------------------------------------------------------------------ */
function RoboProg_fillForm(_config) {
    _config = _config || {};

    $('#rp_robot_name').val(_config.robot_name || '');

    $('#rp_start_cmd_id').val(_config.start_cmd_id || '');
    $('#rp_home_cmd_id').val(_config.home_cmd_id || '');
    $('#rp_edge_enabled').prop('checked', _config.edge_cmd_id ? true : false);
    $('#rp_edge_cmd_id').val(_config.edge_cmd_id || '');
    $('#rp_status_cmd_id').val(_config.status_cmd_id || '');
    $('#rp_error_cmd_id').val(_config.error_cmd_id || '');
    $('#rp_battery_cmd_id').val(_config.battery_cmd_id || '');
    $('#rp_battery_min_percent').val(_config.battery_min_percent != null ? _config.battery_min_percent : 30);
    $('#rp_rain_cmd_id').val(_config.rain_cmd_id || '');
    $('#rp_rain_operator').val(_config.rain_operator || '==');
    $('#rp_rain_value').val(_config.rain_value || '');
    $('#rp_rain_extra_cmd_id').val(_config.rain_extra_cmd_id || '');
    $('#rp_rain_extra_operator').val(_config.rain_extra_operator || '==');
    $('#rp_rain_extra_value').val(_config.rain_extra_value || '');

    $('#rp_time_start_cmd_id').val(_config.time_start_cmd_id || '');
    $('#rp_time_end_cmd_id').val(_config.time_end_cmd_id || '');
    $('#rp_margin_minutes').val(_config.margin_minutes != null ? _config.margin_minutes : 60);
    $('#rp_spacing_days').val(_config.spacing_days != null ? _config.spacing_days : 3);

    $('#rp_humidity_cmd_id').val(_config.humidity_cmd_id || '');
    $('#rp_humidity_threshold').val(_config.humidity_threshold != null ? _config.humidity_threshold : 65);
    $('#rp_humidity_duration_minutes').val(_config.humidity_duration_minutes != null ? _config.humidity_duration_minutes : 180);
    $('#rp_condition_id_cmd_id').val(_config.condition_id_cmd_id || '');
    $('#rp_condition_cmd_id').val(_config.condition_cmd_id || '');

    $('#rp_temperature_cmd_id').val(_config.temperature_cmd_id || '');
    $('#rp_temperature_min').val(_config.temperature_min != null ? _config.temperature_min : 8);
    $('#rp_temperature_max').val(_config.temperature_max != null ? _config.temperature_max : 40);

    $('#rp_rain_interrupt_minutes').val(_config.rain_interrupt_minutes != null ? _config.rain_interrupt_minutes : 60);
    $('#rp_mow_duration_minutes').val(_config.mow_duration_minutes != null ? _config.mow_duration_minutes : 120);

    $('input[name="rp_edge_mode"][value="' + (_config.edge_mode || 'interval') + '"]').prop('checked', true);
    $('#rp_edge_interval_days').val(_config.edge_interval_days != null ? _config.edge_interval_days : 7);
    $('.rp_edge_weekday').prop('checked', false);
    if (Array.isArray(_config.edge_weekdays)) {
        _config.edge_weekdays.forEach(function (d) {
            $('.rp_edge_weekday[value="' + d + '"]').prop('checked', true);
        });
    }
    $('#rp_edge_catchup_enabled').prop('checked', _config.edge_catchup_enabled == '1');
    $('#rp_edge_resume_enabled').prop('checked', _config.edge_resume_enabled == '1');

    $('#rp_enabled').prop('checked', _config.enabled == '1');

    $('#table_notifications tbody').empty();
    if (Array.isArray(_config.notifications)) {
        _config.notifications.forEach(function (n) {
            RoboProg_addNotificationRow(n);
        });
    }

    RoboProg_toggleSections();
    RoboProg_refreshHomeStatusCheck(_config);
    RoboProg_refreshAllPreviews();
    RoboProg_refreshLatestStart();
    RoboProg_refreshNextMow();
}

function RoboProg_toggleSections() {
    var edgeEnabled = $('#rp_edge_enabled').is(':checked');
    $('#fs_edge_cmd').toggle(edgeEnabled);
    if (!edgeEnabled) {
        $('#rp_edge_cmd_id').val('');
    }
    var hasEdge = edgeEnabled && $('#rp_edge_cmd_id').val().trim() !== '';
    $('#fs_edge').toggle(hasEdge);

    var mode = $('input[name="rp_edge_mode"]:checked').val();
    $('#fs_edge_interval').toggle(mode === 'interval');
    $('#fs_edge_weekday').toggle(mode === 'weekday');

    // La relance après bordures s'applique aussi bien au rattrapage qu'à
    // un jour de bordures normal programmé : visible dès que la section
    // bordures l'est, indépendamment de la case rattrapage.
    $('#fs_edge_resume').toggle(hasEdge);
    if (!hasEdge) {
        $('#rp_edge_resume_enabled').prop('checked', false);
    }
}

function RoboProg_refreshHomeStatusCheck(_config) {
    var recorded = _config && _config.status_home_value !== null && _config.status_home_value !== undefined && _config.status_home_value !== '';
    if (recorded) {
        $('#rp_home_status_check').html('<span class="text-success"><i class="fas fa-check-circle"></i> Enregistré : "' + _config.status_home_value + '"</span>');
    } else {
        $('#rp_home_status_check').html('<span class="text-muted"><i class="fas fa-times-circle"></i> Pas encore enregistré</span>');
    }
}

$(document).on('change', 'input[name="rp_edge_mode"], #rp_edge_catchup_enabled, #rp_edge_enabled', function () {
    RoboProg_toggleSections();
});
$(document).on('input change', '#rp_edge_cmd_id', function () {
    RoboProg_toggleSections();
});

/* ------------------------------------------------------------------ */
/* Aperçu en direct (✅/❌) de chaque commande, même mécanisme que      */
/* LandroidRTK : un span .cmdValuePreview référencé via data-input.     */
/* ------------------------------------------------------------------ */
function RoboProg_appendRainComparison($preview, currentValue, operatorSelector, valueSelector) {
    var operator = $(operatorSelector).val();
    var expected = $(valueSelector).val();
    if (expected === '') {
        return;
    }
    $preview.append(' <span class="text-muted">— comparaison : "' + currentValue + '" ' + (operator == '!=' ? '≠' : '==') + ' "' + expected + '"</span>');
}

function RoboProg_refreshPreview($input) {
    var raw = $input.val();
    var $preview;
    var inputId = $input.attr('id');
    if (inputId) {
        $preview = $('.cmdValuePreview[data-input="#' + inputId + '"]');
    }
    if (!$preview || !$preview.length) {
        // Lignes de notification (input sans id fixe, clonées) : le span
        // est dans la même cellule que l'input.
        $preview = $input.closest('td').find('.cmdValuePreview');
    }
    if (!raw) {
        $preview.html('');
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: {
            action: 'previewValue',
            raw: raw,
            min: $preview.data('min') != null ? $preview.data('min') : '',
            max: $preview.data('max') != null ? $preview.data('max') : '',
            apikey: RoboProgApikey,
        },
        dataType: 'json',
        success: function (data) {
            if (data.state != 'ok') {
                $preview.html('');
                return;
            }
            var r = data.result;
            if (r.valid === null) {
                $preview.html('');
            } else if (r.valid) {
                $preview.html('<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> ' + r.value + '</span>');
                if (inputId == 'rp_rain_cmd_id' && r.value != null) {
                    RoboProg_appendRainComparison($preview, r.value, '#rp_rain_operator', '#rp_rain_value');
                }
                if (inputId == 'rp_rain_extra_cmd_id' && r.value != null) {
                    RoboProg_appendRainComparison($preview, r.value, '#rp_rain_extra_operator', '#rp_rain_extra_value');
                }
            } else {
                $preview.html('<span style="color:#a94442;"><i class="fas fa-times-circle"></i> ' + (r.error || 'Erreur') + (r.value ? ' (' + r.value + ')' : '') + '</span>');
            }
        }
    });
}

$(document).on('change blur', '#scheduletab input[type=text].form-control, #scheduletab .notif_cmd_id', function () {
    RoboProg_refreshPreview($(this));
});

$(document).on('change blur', '#rp_rain_operator, #rp_rain_value', function () {
    RoboProg_refreshPreview($('#rp_rain_cmd_id'));
});
$(document).on('change blur', '#rp_rain_extra_operator, #rp_rain_extra_value', function () {
    RoboProg_refreshPreview($('#rp_rain_extra_cmd_id'));
});

function RoboProg_refreshLatestStart() {
    var $preview = $('#rp_latest_start_preview');
    var timeStart = $('#rp_time_start_cmd_id').val();
    var timeEnd = $('#rp_time_end_cmd_id').val();
    var margin = $('#rp_margin_minutes').val();
    if (!timeStart || !timeEnd) {
        $preview.html('');
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: {
            action: 'latestStartPreview',
            apikey: RoboProgApikey,
            time_start: timeStart,
            time_end: timeEnd,
            margin_minutes: margin,
        },
        dataType: 'json',
        success: function (data) {
            if (data.state != 'ok') {
                $preview.html('');
                return;
            }
            var r = data.result;
            if (r.valid) {
                $preview.html('<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> Dernier départ : ' + r.value + '</span>');
            } else {
                $preview.html('<span style="color:#a94442;"><i class="fas fa-times-circle"></i> ' + (r.error || 'Erreur') + '</span>');
            }
        }
    });
}

$(document).on('change blur', '#rp_time_start_cmd_id, #rp_time_end_cmd_id, #rp_margin_minutes', function () {
    RoboProg_refreshLatestStart();
});

function RoboProg_refreshAllPreviews() {
    $('#scheduletab input[type=text].form-control, #scheduletab .notif_cmd_id').each(function () {
        RoboProg_refreshPreview($(this));
    });
}

/* ------------------------------------------------------------------ */
/* Sélecteur de commande natif Jeedom                                   */
/* ------------------------------------------------------------------ */
$(document).on('click', '.bt_openCmdPicker', function (e) {
    e.preventDefault();
    var $bt = $(this);
    var $target;
    if ($bt.data('target-self')) {
        $target = $bt.closest('td').find('input');
    } else {
        $target = $($bt.data('target'));
    }
    var filter = { type: $bt.data('cmdtype') || 'info' };
    if ($bt.data('cmdsubtype')) {
        filter.subType = $bt.data('cmdsubtype');
    }
    jeedom.cmd.getSelectModal({ cmd: filter }, function (result) {
        if (result && result.human) {
            $target.value(result.human);
            RoboProg_toggleSections();
            RoboProg_refreshPreview($target);
        }
    });
});

/* ------------------------------------------------------------------ */
/* Notifications : gestion des lignes                                  */
/* ------------------------------------------------------------------ */
function RoboProg_addNotificationRow(_notif) {
    _notif = _notif || {};
    var $tr = $('.notificationTemplate').clone();
    $tr.removeClass('notificationTemplate').show();
    $tr.find('.notif_cmd_id').val(_notif.cmd_id || '');
    $tr.find('.notif_title').val(_notif.title || '');
    $tr.find('.notif_html').prop('checked', _notif.html == '1');
    $tr.find('.notif_no_mow').prop('checked', _notif.notify_no_mow != '0');
    $tr.find('.notif_error').prop('checked', _notif.notify_error == '1');
    $tr.find('.notif_edge_catchup').prop('checked', _notif.notify_edge_catchup == '1');
    $tr.find('.notif_edge_resume').prop('checked', _notif.notify_edge_resume == '1');
    $('#table_notifications tbody').append($tr);
    RoboProg_refreshPreview($tr.find('.notif_cmd_id'));
}

$(document).on('click', '#bt_addNotification', function (e) {
    e.preventDefault();
    RoboProg_addNotificationRow();
});

$(document).on('click', '#table_notifications .bt_testNotifRow', function (e) {
    e.preventDefault();
    var $tr = $(this).closest('tr');
    var cmd_raw = $tr.find('.notif_cmd_id').val();
    if (!cmd_raw) {
        $.fn.showAlert({ message: '{{Renseigne une commande avant de tester cette ligne}}', level: 'warning' });
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: {
            action: 'testNotification',
            id: RoboProg_eqId(),
            apikey: RoboProgApikey,
            cmd_id: cmd_raw,
            title: $tr.find('.notif_title').val(),
            html: $tr.find('.notif_html').is(':checked') ? '1' : '0',
        },
        dataType: 'json',
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state == 'ok') {
                $.fn.showAlert({ message: '{{Message de test envoyé}}', level: 'success' });
            } else {
                $.fn.showAlert({ message: data.result || '{{Échec de l\'envoi}}', level: 'danger' });
            }
        }
    });
});

$(document).on('click', '.bt_removeRow', function (e) {
    e.preventDefault();
    $(this).closest('tr').remove();
});

/* ------------------------------------------------------------------ */
/* Construction de la config à partir du formulaire                    */
/* ------------------------------------------------------------------ */
function RoboProg_buildConfig() {
    var notifications = [];
    $('#table_notifications tbody tr').each(function () {
        var $tr = $(this);
        notifications.push({
            cmd_id: $tr.find('.notif_cmd_id').val(),
            title: $tr.find('.notif_title').val(),
            html: $tr.find('.notif_html').is(':checked') ? '1' : '0',
            notify_no_mow: $tr.find('.notif_no_mow').is(':checked') ? '1' : '0',
            notify_error: $tr.find('.notif_error').is(':checked') ? '1' : '0',
            notify_edge_catchup: $tr.find('.notif_edge_catchup').is(':checked') ? '1' : '0',
            notify_edge_resume: $tr.find('.notif_edge_resume').is(':checked') ? '1' : '0',
        });
    });

    var edge_weekdays = [];
    $('.rp_edge_weekday:checked').each(function () {
        edge_weekdays.push($(this).val());
    });

    return {
        enabled: $('#rp_enabled').is(':checked') ? '1' : '0',
        robot_name: $('#rp_robot_name').val(),

        start_cmd_id: $('#rp_start_cmd_id').val(),
        home_cmd_id: $('#rp_home_cmd_id').val(),
        edge_cmd_id: $('#rp_edge_cmd_id').val(),
        status_cmd_id: $('#rp_status_cmd_id').val(),
        error_cmd_id: $('#rp_error_cmd_id').val(),
        battery_cmd_id: $('#rp_battery_cmd_id').val(),
        battery_min_percent: $('#rp_battery_min_percent').val(),
        rain_cmd_id: $('#rp_rain_cmd_id').val(),
        rain_operator: $('#rp_rain_operator').val(),
        rain_value: $('#rp_rain_value').val(),
        rain_extra_cmd_id: $('#rp_rain_extra_cmd_id').val(),
        rain_extra_operator: $('#rp_rain_extra_operator').val(),
        rain_extra_value: $('#rp_rain_extra_value').val(),

        time_start_cmd_id: $('#rp_time_start_cmd_id').val(),
        time_end_cmd_id: $('#rp_time_end_cmd_id').val(),
        margin_minutes: $('#rp_margin_minutes').val(),
        spacing_days: $('#rp_spacing_days').val(),

        humidity_cmd_id: $('#rp_humidity_cmd_id').val(),
        humidity_threshold: $('#rp_humidity_threshold').val(),
        humidity_duration_minutes: $('#rp_humidity_duration_minutes').val(),
        condition_id_cmd_id: $('#rp_condition_id_cmd_id').val(),
        condition_cmd_id: $('#rp_condition_cmd_id').val(),

        temperature_cmd_id: $('#rp_temperature_cmd_id').val(),
        temperature_min: $('#rp_temperature_min').val(),
        temperature_max: $('#rp_temperature_max').val(),

        rain_interrupt_minutes: $('#rp_rain_interrupt_minutes').val(),
        mow_duration_minutes: $('#rp_mow_duration_minutes').val(),

        edge_mode: $('input[name="rp_edge_mode"]:checked').val() || 'interval',
        edge_interval_days: $('#rp_edge_interval_days').val(),
        edge_weekdays: edge_weekdays,
        edge_catchup_enabled: $('#rp_edge_catchup_enabled').is(':checked') ? '1' : '0',
        edge_resume_enabled: $('#rp_edge_resume_enabled').is(':checked') ? '1' : '0',

        notifications: notifications,
    };
}

/* ------------------------------------------------------------------ */
/* Chargement initial                                                   */
/* ------------------------------------------------------------------ */
function RoboProg_refreshNextMow() {
    var config = RoboProg_buildConfig();
    if (config.enabled != '1') {
        $('#rp_next_mow').html('<i class="fas fa-info-circle"></i> Active la programmation ci-dessus pour voir l\'estimation de la prochaine tonte.').removeClass('alert-info').addClass('alert-warning').show();
        return;
    }
    $('#rp_next_mow').removeClass('alert-warning').addClass('alert-info');
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: {
            action: 'nextMowEstimate',
            id: RoboProg_eqId(),
            apikey: RoboProgApikey,
            config: JSON.stringify(config),
        },
        dataType: 'json',
        success: function (data) {
            if (data.state == 'ok' && data.result && data.result.text) {
                $('#rp_next_mow').html('<i class="fas fa-hourglass-half"></i> ' + data.result.text).show();
            } else {
                $('#rp_next_mow').hide();
            }
        }
    });
}

function RoboProg_loadConditionsStatus() {
    var $body = $('#rp_conditions_status_body');
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: { action: 'conditionsStatus', id: RoboProg_eqId(), apikey: RoboProgApikey },
        dataType: 'json',
        success: function (data) {
            if (data.state != 'ok' || !data.result || !data.result.rows) {
                return;
            }
            $body.empty();
            $.each(data.result.rows, function (i, row) {
                var icon = row.ok
                    ? '<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> OK</span>'
                    : '<span style="color:#a94442;"><i class="fas fa-times-circle"></i> Non</span>';
                $body.append(
                    '<tr><td>' + row.label + '</td><td>' + icon
                    + (row.detail ? ' <span class="text-muted" style="font-size:0.9em;">(' + row.detail + ')</span>' : '')
                    + '</td></tr>'
                );
            });
        }
    });
}

$(document).on('click', '#bt_refreshConditionsStatus', function (e) {
    e.preventDefault();
    RoboProg_loadConditionsStatus();
});

/* ------------------------------------------------------------------ */
/* Chargement initial                                                   */
/* ------------------------------------------------------------------ */
function RoboProg_load(_eqLogic_id) {
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: { action: 'getConfig', id: _eqLogic_id || RoboProg_eqId(), apikey: RoboProgApikey },
        dataType: 'json',
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state != 'ok') {
                return;
            }
            RoboProg_fillForm(data.result);
            if (data.result.enabled == '1') {
                RoboProg_loadConditionsStatus();
                $('#rp_conditions_status').show();
            } else {
                $('#rp_conditions_status').hide();
            }
        }
    });
}

/* ------------------------------------------------------------------ */
/* Sauvegarder                                                          */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_saveConfig', function (e) {
    e.preventDefault();
    var config = RoboProg_buildConfig();
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: { action: 'saveConfig', id: RoboProg_eqId(), config: JSON.stringify(config), apikey: RoboProgApikey },
        dataType: 'json',
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state != 'ok') {
                $('#div_alert').removeClass('alert-success').addClass('alert-danger').html('{{Échec de la sauvegarde}} : ' + (data.result || '')).show();
                return;
            }
            if (data.result.errors && data.result.errors.length) {
                config.enabled = '0';
                $('#rp_enabled').prop('checked', false);
                $('#div_alert').removeClass('alert-success').addClass('alert-danger')
                    .html('<b>{{Configuration enregistrée, mais désactivée automatiquement car invalide}} :</b><br>' + data.result.errors.join('<br>')).show();
                return;
            }
            $('#div_alert').removeClass('alert-danger').addClass('alert-success').html('{{Configuration enregistrée}}').show();
            RoboProg_fillForm(config);
            if (config.enabled == '1') {
                $('#rp_conditions_status').show();
                RoboProg_loadConditionsStatus();
            } else {
                $('#rp_conditions_status').hide();
            }
        }
    });
});

/* ------------------------------------------------------------------ */
/* Tester                                                                */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_testConfig', function (e) {
    e.preventDefault();
    var config = RoboProg_buildConfig();
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: { action: 'testConfig', id: RoboProg_eqId(), config: JSON.stringify(config), apikey: RoboProgApikey },
        dataType: 'json',
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state != 'ok') {
                $('#div_alert').removeClass('alert-success').addClass('alert-danger').html('{{Échec du test}} : ' + (data.result || '')).show();
                return;
            }
            if (!data.result.valid) {
                $('#div_alert').removeClass('alert-success').addClass('alert-danger').html(data.result.errors.join('<br>')).show();
                return;
            }
            $('#div_alert').removeClass('alert-danger').addClass('alert-success').html('{{Configuration valide, aucune erreur détectée}}').show();
        }
    });
});

/* ------------------------------------------------------------------ */
/* Enregistrer le statut "maison"                                       */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_recordHomeStatus', function (e) {
    e.preventDefault();
    if (!confirm('{{Le robot est-il bien physiquement à la base actuellement ?}}')) {
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: { action: 'recordHomeStatus', id: RoboProg_eqId(), apikey: RoboProgApikey },
        dataType: 'json',
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state != 'ok') {
                $.fn.showAlert({ message: data.result || '{{Échec}}', level: 'danger' });
                return;
            }
            $.fn.showAlert({ message: '{{Statut "maison" enregistré}}', level: 'success' });
            RoboProg_refreshHomeStatusCheck({ status_home_value: data.result });
        }
    });
});

/* ------------------------------------------------------------------ */
/* Outils de débogage                                                    */
/* ------------------------------------------------------------------ */
function RoboProg_debugAction(action, confirmMsg, successMsg) {
    if (confirmMsg && !confirm(confirmMsg)) {
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/RoboProg/core/ajax/RoboProg.ajax.php',
        data: { action: action, id: RoboProg_eqId(), apikey: RoboProgApikey },
        dataType: 'json',
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state != 'ok') {
                $.fn.showAlert({ message: '{{Échec}} : ' + (data.result || ''), level: 'danger' });
                return;
            }
            $.fn.showAlert({ message: successMsg, level: 'success' });
        }
    });
}

$(document).on('click', '#bt_debugMowYesterday', function (e) {
    e.preventDefault();
    RoboProg_debugAction('debugMowYesterday', null, '{{Dernière tonte réglée à hier}}');
});
$(document).on('click', '#bt_markMowToday', function (e) {
    e.preventDefault();
    RoboProg_debugAction('markMowToday', null, '{{Tonte du jour marquée comme faite}}');
});
$(document).on('click', '#bt_resetNotifThrottle', function (e) {
    e.preventDefault();
    RoboProg_debugAction('resetNotifThrottle', '{{Réinitialiser l\'anti-doublon des notifications ?}}', '{{Anti-doublon réinitialisé}}');
});
