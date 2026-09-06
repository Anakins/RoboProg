<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

try {
    // Même mécanisme que LandroidRTK : la session isConnect('admin') s'est
    // révélée peu fiable dans cet environnement — on accepte donc aussi la
    // clé API du plugin en secours.
    $apikey = init('apikey');
    $expected_apikey = RoboProg::getApiKey();
    $authorized_by_apikey = ($apikey != '' && $apikey == $expected_apikey);
    if (!$authorized_by_apikey && !isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    if (init('action') == 'testNotification') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        $config = RoboProg::getConfig($eqLogic);
        $cmd_id = init('cmd_id');
        $title = init('title');
        $html = init('html');
        if (empty($cmd_id)) {
            throw new Exception('Renseignez une commande avant de tester cette ligne.');
        }
        $robot_name = !empty($config['robot_name']) ? $config['robot_name'] : $eqLogic->getHumanName();
        $default_title = strtoupper($robot_name) . ' - TONTE';
        $test_config = $config;
        $test_config['notifications'] = array(array(
            'cmd_id' => $cmd_id,
            'title' => '[TEST] ' . (!empty($title) ? $title : $default_title),
            'html' => $html,
        ));
        $text = "[TEST] ✂️ {$robot_name} va tondre la pelouse.";
        RoboProg::sendNotifications($test_config, '[TEST] ' . $default_title, $text, $text);
        ajax::success(true);
    }

    if (init('action') == 'previewValue') {
        $raw = init('raw');
        $min = init('min') !== '' ? init('min') : null;
        $max = init('max') !== '' ? init('max') : null;
        ajax::success(RoboProg::previewValue($raw, $min, $max));
    }

    if (init('action') == 'latestStartPreview') {
        $result = RoboProg::previewLatestStart(init('time_start'), init('time_end'), init('margin_minutes'));
        ajax::success($result);
    }

    if (init('action') == 'nextMowEstimate') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        $config = json_decode(init('config'), true);
        if (!is_array($config)) {
            throw new Exception('Configuration invalide (JSON illisible)');
        }
        $config = array_merge(RoboProg::getConfig($eqLogic), $config);
        ajax::success(RoboProg::estimateNextMow($eqLogic, $config));
    }

    if (init('action') == 'conditionsStatus') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        $config = RoboProg::getConfig($eqLogic);
        ajax::success(RoboProg::getConditionsStatus($eqLogic, $config));
    }

    if (init('action') == 'getConfig') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        ajax::success(RoboProg::getConfig($eqLogic));
    }

    if (init('action') == 'saveConfig') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        $config = json_decode(init('config'), true);
        if (!is_array($config)) {
            throw new Exception('Configuration invalide (JSON illisible)');
        }
        $config = array_merge(RoboProg::getConfig($eqLogic), $config);

        // Jamais confiance uniquement au JS : si l'utilisateur essaie
        // d'ACTIVER, on revalide côté serveur avant d'autoriser.
        $errors = array();
        if (!empty($config['enabled']) && $config['enabled'] == '1') {
            $checked = RoboProg::checkConfig($config);
            if ($checked !== true) {
                $errors = $checked;
                $config['enabled'] = '0';
            }
        }

        RoboProg::saveConfig($eqLogic, $config);
        RoboProg::syncCmds($eqLogic);

        ajax::success(array(
            'saved' => true,
            'enabled' => (!empty($config['enabled']) && $config['enabled'] == '1'),
            'errors' => $errors,
        ));
    }

    if (init('action') == 'testConfig') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        $config = json_decode(init('config'), true);
        if (!is_array($config)) {
            throw new Exception('Configuration invalide (JSON illisible)');
        }
        $config = array_merge(RoboProg::getConfig($eqLogic), $config);
        $checked = RoboProg::checkConfig($config);
        ajax::success(array(
            'valid' => ($checked === true),
            'errors' => ($checked === true ? array() : $checked),
        ));
    }

    if (init('action') == 'recordHomeStatus') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        $value = RoboProg::recordHomeStatus($eqLogic);
        ajax::success($value);
    }

    if (init('action') == 'debugMowYesterday') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        RoboProg::debugMowYesterday($eqLogic);
        ajax::success(true);
    }

    if (init('action') == 'markMowToday') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        RoboProg::markLastMowToday($eqLogic);
        ajax::success(true);
    }

    if (init('action') == 'resetNotifThrottle') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception("Équipement introuvable (avez-vous bien sauvegardé l'équipement au moins une fois via l'onglet Équipement ?)");
        }
        RoboProg::resetNotifThrottle($eqLogic);
        ajax::success(true);
    }

    throw new Exception(__('Aucun paramètre valide donné', __FILE__));
} catch (\Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
