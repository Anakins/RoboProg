<?php

if (!defined('__ROOT__')) {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
}

/*
 * RoboProg — planificateur de tonte générique.
 *
 * Contrairement à LandroidRTK (dédié aux robots Worx Vision Cloud, avec
 * communication directe à l'API du fabricant), RoboProg ne parle JAMAIS
 * au robot lui-même. Il se contente de piloter les COMMANDES d'un
 * équipement robot déjà existant dans Jeedom (quel qu'en soit le
 * fabricant/plugin d'origine), au bon moment, selon des règles météo et
 * un calendrier.
 *
 * Toutes les commandes robot (start/home/edge/status/battery/error/rain)
 * sont donc des références ("tags") vers des commandes d'AUTRES
 * équipements, exactement comme le sont déjà temperature_cmd_id ou
 * condition_cmd_id dans LandroidRTKScheduler.
 *
 * Seule "start" est strictement obligatoire. Chaque commande
 * supplémentaire liée débloque un bloc de fonctions associé :
 *   - home  -> gestion de la pluie (retour forcé + reprise après délai)
 *              + relance automatique du cycle classique après un
 *                rattrapage de bordures (voir plus bas)
 *   - edge  -> programmation dédiée à la coupe des bordures
 *   - status + "valeur à la maison" enregistrée -> détection fiable du
 *              retour à la base (nécessaire à la relance après bordures)
 *   - battery -> vérification de sécurité avant tout envoi de commande
 *   - error / rain -> informatif + sécurité supplémentaire
 */
class RoboProg extends eqLogic {

    /* ================================================================ */
    /* Résolution de commandes / valeurs (mêmes conventions que          */
    /* LandroidRTK : tag Jeedom #[Objet][Équipement][Commande]# ou ID)   */
    /* ================================================================ */

    public static function resolveCmd($input) {
        if ($input === null || $input === '') {
            return null;
        }
        $input = trim($input);
        // On exige le format tag Jeedom complet #[Objet][Équipement][Commande]#
        // (celui inséré automatiquement par le sélecteur). Un simple ID
        // numérique tapé au clavier n'est plus accepté : il pourrait
        // coïncider par hasard avec une vraie commande et sembler valide
        // à tort, alors que ce n'est pas ce que l'utilisateur voulait dire.
        if (!preg_match('/^#\[[^\]]*\]\[[^\]]*\]\[[^\]]*\]#$/', $input)) {
            return null;
        }
        try {
            $resolved = cmd::humanReadableToCmd($input);
            if ($resolved != $input && preg_match('/#([0-9]+)#/', $resolved, $m)) {
                $cmd = cmd::byId($m[1]);
                return is_object($cmd) ? $cmd : null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    public static function getCmdValue($input) {
        $cmd = self::resolveCmd($input);
        if (!is_object($cmd)) {
            return null;
        }
        $v = $cmd->execCmd();
        return $v;
    }

    /* ================================================================ */
    /* Configuration                                                     */
    /* ================================================================ */

    public static function getConfig($eqLogic) {
        $raw = $eqLogic->getConfiguration('config', '');
        return self::parseConfig($raw);
    }

    public static function parseConfig($raw) {
        $default = array(
            'enabled' => '0',
            'robot_name' => '',

            // Commandes robot (tags vers d'autres équipements)
            'start_cmd_id'  => '',
            'home_cmd_id'   => '',
            'edge_cmd_id'   => '',
            'status_cmd_id' => '',
            'status_home_value' => null,
            'error_cmd_id'  => '',
            'battery_cmd_id' => '',
            'rain_cmd_id'   => '',
            'rain_operator' => '==',
            'rain_value'    => '',
            'rain_extra_cmd_id'   => '',
            'rain_extra_operator' => '==',
            'rain_extra_value'    => '',

            // Plage horaire / espacement (tonte classique)
            'time_start_cmd_id' => '',
            'time_end_cmd_id'   => '',
            'margin_minutes'    => '60',
            'mow_duration_minutes' => '120',
            'spacing_days'      => '3',

            // Météo (humidité obligatoire, reste optionnel)
            'humidity_cmd_id' => '',
            'humidity_threshold' => '65',
            'humidity_duration_minutes' => '180',
            'temperature_cmd_id' => '',
            'temperature_min' => '8',
            'temperature_max' => '40',
            'condition_id_cmd_id' => '',
            'condition_cmd_id' => '',
            'rain_interrupt_minutes' => '60',

            // Sécurité
            'battery_min_percent' => '30',

            // Bordures
            'edge_mode' => 'interval',       // 'interval' ou 'weekday'
            'edge_interval_days' => '7',
            'edge_weekdays' => array(),       // ex: ['1','3'] (1=lundi ... 7=dimanche)
            'edge_catchup_enabled' => '0',
            'edge_resume_enabled' => '0',

            'notifications' => array(),
        );
        if ($raw == '' || $raw === null) {
            return $default;
        }
        $parsed = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($parsed)) {
            return $default;
        }
        return array_merge($default, $parsed);
    }

    public static function saveConfig($eqLogic, $config) {
        // Si le seuil d'humidité est ABAISSÉ (plus strict), on réinitialise
        // le suivi du délai : le temps déjà écoulé a été mesuré par
        // rapport à l'ancien seuil, plus permissif. S'il est RELEVÉ (plus
        // permissif), on ne touche à rien.
        $old_config = self::getConfig($eqLogic);
        if (isset($old_config['humidity_threshold']) && isset($config['humidity_threshold'])
            && is_numeric($old_config['humidity_threshold']) && is_numeric($config['humidity_threshold'])
            && floatval($config['humidity_threshold']) < floatval($old_config['humidity_threshold'])) {
            $state = self::getState($eqLogic);
            if ($state['humidity_low_since'] !== null) {
                $state['humidity_low_since'] = null;
                self::saveState($eqLogic, $state);
                log::add('RoboProg', 'info', $config['robot_name'] . " : seuil d'humidité abaissé, délai de confirmation réinitialisé.");
            }
        }

        $eqLogic->setConfiguration('config', json_encode($config));
        $eqLogic->save();
    }

    /*
     * Validation stricte avant d'autoriser l'activation. Retourne soit
     * true, soit un tableau d'erreurs (libellés lisibles).
     */
    public static function checkConfig($config) {
        $errors = array();

        if (empty($config['start_cmd_id']) || !is_object(self::resolveCmd($config['start_cmd_id']))) {
            $errors[] = "La commande pour lancer la tonte est obligatoire et doit pointer vers une commande Jeedom valide.";
        }
        if (empty(trim((string) $config['robot_name']))) {
            $errors[] = "Le nom du robot est obligatoire (utilisé dans les notifications et les logs).";
        } elseif (!preg_match('/[a-zA-ZÀ-ÖØ-öø-ÿ]/', $config['robot_name'])) {
            $errors[] = "Le nom du robot doit contenir au moins une lettre (pas uniquement des chiffres).";
        }
        if (empty($config['humidity_cmd_id']) || !is_object(self::resolveCmd($config['humidity_cmd_id']))) {
            $errors[] = "La commande d'humidité (via un plugin météo) est obligatoire et doit pointer vers une commande Jeedom valide.";
        }
        if (empty($config['condition_id_cmd_id']) || !is_object(self::resolveCmd($config['condition_id_cmd_id']))) {
            $errors[] = "La commande de code météo (via un plugin météo) est obligatoire et doit pointer vers une commande Jeedom valide.";
        }
        if (empty($config['condition_cmd_id']) || !is_object(self::resolveCmd($config['condition_cmd_id']))) {
            $errors[] = "La commande de condition météo (libellé, via un plugin météo) est obligatoire et doit pointer vers une commande Jeedom valide.";
        }

        // Commandes optionnelles : si renseignées, doivent être valides.
        foreach (array(
            'home_cmd_id' => 'Commande de retour à la maison',
            'edge_cmd_id' => 'Commande pour lancer la tonte des bordures',
            'status_cmd_id' => 'Commande de statut',
            'error_cmd_id' => "Commande d'erreur",
            'battery_cmd_id' => 'Commande de batterie',
            'rain_cmd_id' => 'Capteur pluie (1)',
            'rain_extra_cmd_id' => 'Capteur pluie (2)',
            'temperature_cmd_id' => 'Température',
        ) as $key => $label) {
            if (!empty($config[$key]) && !is_object(self::resolveCmd($config[$key]))) {
                $errors[] = "$label : la commande renseignée est introuvable.";
            }
        }

        // Capteur pluie : dès qu'une commande est liée, une valeur de
        // comparaison est obligatoire (pas de nomenclature universelle
        // possible ici, contrairement à un robot dédié comme Worx).
        if (!empty($config['rain_cmd_id'])) {
            if ($config['rain_value'] === '' || $config['rain_value'] === null) {
                $errors[] = "Capteur pluie (1) : une valeur de comparaison est obligatoire (ex: \"1\", \"oui\"...).";
            }
            if (!in_array($config['rain_operator'], array('==', '!='))) {
                $errors[] = "Capteur pluie (1) : opérateur de comparaison invalide.";
            }
        }
        if (!empty($config['rain_extra_cmd_id'])) {
            if ($config['rain_extra_value'] === '' || $config['rain_extra_value'] === null) {
                $errors[] = "Capteur pluie (2) : une valeur de comparaison est obligatoire (ex: \"1\", \"oui\"...).";
            }
            if (!in_array($config['rain_extra_operator'], array('==', '!='))) {
                $errors[] = "Capteur pluie (2) : opérateur de comparaison invalide.";
            }
        }

        // Plage horaire : nécessaire dès lors qu'on programme une tonte.
        if (empty($config['time_start_cmd_id'])) {
            $errors[] = "La commande (ou heure fixe) de début de la plage horaire est obligatoire.";
        }
        if (empty($config['time_end_cmd_id'])) {
            $errors[] = "La commande (ou heure fixe) de fin de la plage horaire est obligatoire.";
        }
        if (!is_numeric($config['margin_minutes']) || $config['margin_minutes'] < 0 || $config['margin_minutes'] > 600) {
            $errors[] = "La marge avant l'heure de fin doit être comprise entre 0 et 600 minutes.";
        }
        if (!is_numeric($config['mow_duration_minutes']) || $config['mow_duration_minutes'] < 30 || $config['mow_duration_minutes'] > 360) {
            $errors[] = "La durée de tonte estimée doit être comprise entre 30 et 360 minutes (6h).";
        }
        if (!is_numeric($config['spacing_days']) || $config['spacing_days'] < 1 || $config['spacing_days'] > 30) {
            $errors[] = "L'espacement entre tontes doit être compris entre 1 et 30 jours.";
        }
        if (!is_numeric($config['humidity_threshold']) || $config['humidity_threshold'] < 0 || $config['humidity_threshold'] > 100) {
            $errors[] = "Le seuil d'humidité doit être compris entre 0 et 100%.";
        }
        if (!is_numeric($config['humidity_duration_minutes']) || $config['humidity_duration_minutes'] < 0) {
            $errors[] = "Le délai d'humidité doit être un nombre de minutes positif.";
        }
        if (!empty($config['temperature_cmd_id'])) {
            if (!is_numeric($config['temperature_min']) || $config['temperature_min'] < 4 || $config['temperature_min'] > 18) {
                $errors[] = "Le seuil minimum de température doit être compris entre 4 et 18°C.";
            }
            if (!is_numeric($config['temperature_max']) || $config['temperature_max'] < 30 || $config['temperature_max'] > 50) {
                $errors[] = "Le seuil maximum de température doit être compris entre 30 et 50°C.";
            }
        }
        if (!empty($config['battery_cmd_id'])) {
            if (!is_numeric($config['battery_min_percent']) || $config['battery_min_percent'] < 0 || $config['battery_min_percent'] > 100) {
                $errors[] = "Le seuil minimum de batterie doit être compris entre 0 et 100%.";
            }
        }
        if (!is_numeric($config['rain_interrupt_minutes']) || $config['rain_interrupt_minutes'] < 20 || $config['rain_interrupt_minutes'] > 120) {
            $errors[] = "Le délai avant redémarrage après pluie doit être compris entre 20 et 120 minutes.";
        }

        // Bordures : cohérence des options, uniquement si edge_cmd_id lié.
        if (!empty($config['edge_cmd_id'])) {
            $edge_every_day = false;
            if ($config['edge_mode'] == 'interval') {
                if (!is_numeric($config['edge_interval_days']) || $config['edge_interval_days'] < 1 || $config['edge_interval_days'] > 7) {
                    $errors[] = "L'intervalle entre deux coupes de bordures doit être compris entre 1 et 7 jours.";
                } elseif (intval($config['edge_interval_days']) == 1) {
                    $edge_every_day = true;
                }
            } elseif ($config['edge_mode'] == 'weekday') {
                if (empty($config['edge_weekdays']) || !is_array($config['edge_weekdays'])) {
                    $errors[] = "Sélectionnez au moins un jour de la semaine pour la coupe des bordures.";
                } elseif (count(array_unique(array_map('strval', $config['edge_weekdays']))) >= 7) {
                    $edge_every_day = true;
                }
            } else {
                $errors[] = "Mode de programmation des bordures invalide.";
            }
            // Si les bordures sont programmées TOUS les jours, la tonte
            // classique ne pourrait jamais avoir lieu — sauf si la relance
            // automatique est activée (les bordures enchaînent alors
            // systématiquement sur une tonte). Bloquant : il faut qu'au
            // moins un jour de tonte classique reste possible.
            if ($edge_every_day && $config['edge_resume_enabled'] != '1') {
                $errors[] = "Bordures programmées tous les jours sans relance automatique activée : la tonte classique ne pourrait jamais avoir lieu. Activez la relance automatique, ou laissez au moins un jour sans bordures.";
            }
            if ($config['edge_resume_enabled'] == '1' && (empty($config['home_cmd_id']) || empty($config['status_cmd_id']) || $config['status_home_value'] === null || $config['status_home_value'] === '')) {
                $errors[] = "La relance automatique après bordures nécessite : la commande de retour à la maison, la commande Statut, ET d'avoir enregistré la valeur du statut \"à la maison\".";
            }
        } else {
            if ($config['edge_catchup_enabled'] == '1' || $config['edge_resume_enabled'] == '1') {
                $errors[] = "Les options de bordures nécessitent de lier la commande pour lancer la tonte des bordures.";
            }
        }

        return empty($errors) ? true : $errors;
    }

    /* ================================================================ */
    /* État interne (persisté à part, comme LandroidRTKScheduler)        */
    /* ================================================================ */

    private static function getState($eqLogic) {
        $raw = $eqLogic->getConfiguration('state', '');
        $default = array(
            'last_mow_date' => null,
            'last_edge_date' => null,
            'edge_catchup_pending' => false,
            'cycle_phase' => null,          // null | 'edge_in_progress'
            'cycle_phase_since' => null,
            'rain_interrupt_until' => null,
            'humidity_low_since' => null,
            'current_mow_started_at' => null,
            'current_edge_started_at' => null,
            'last_notification_reason' => null,
            'last_notification_date' => null,
            'error_pending_label' => null,
            'error_pending_since' => null,
            'error_notified_label' => null,
        );
        if ($raw == '') {
            return $default;
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return $default;
        }
        return array_merge($default, $parsed);
    }

    private static function saveState($eqLogic, $state) {
        $eqLogic->setConfiguration('state', json_encode($state));
        $eqLogic->save();
    }

    /* ================================================================ */
    /* Outils de débogage (mêmes principes que LandroidRTK)               */
    /* ================================================================ */

    public static function markLastMowToday($eqLogic) {
        $state = self::getState($eqLogic);
        $state['last_mow_date'] = date('Y-m-d');
        $state['cycle_phase'] = null;
        $state['cycle_phase_since'] = null;
        $state['rain_interrupt_until'] = null;
        self::saveState($eqLogic, $state);
        log::add('RoboProg', 'info', $config['robot_name'] . ' : dernière tonte marquée comme faite aujourd\'hui.');
        return $state;
    }

    public static function debugMowYesterday($eqLogic) {
        $state = self::getState($eqLogic);
        $state['last_mow_date'] = date('Y-m-d', strtotime('-1 day'));
        self::saveState($eqLogic, $state);
        log::add('RoboProg', 'info', $config['robot_name'] . ' : [débogage] dernière tonte réglée à hier.');
        return $state;
    }

    public static function resetNotifThrottle($eqLogic) {
        $state = self::getState($eqLogic);
        $state['last_notification_reason'] = null;
        $state['last_notification_date'] = null;
        self::saveState($eqLogic, $state);
        log::add('RoboProg', 'info', $config['robot_name'] . ' : [débogage] anti-doublon des notifications réinitialisé.');
    }

    // Capture la valeur ACTUELLE de la commande Statut et la mémorise
    // comme référence "robot à la maison". À utiliser quand le robot est
    // physiquement à la base.
    public static function recordHomeStatus($eqLogic) {
        $config = self::getConfig($eqLogic);
        if (empty($config['status_cmd_id'])) {
            throw new Exception("Aucune commande Statut n'est liée.");
        }
        $value = self::getCmdValue($config['status_cmd_id']);
        if ($value === null || $value === '') {
            throw new Exception("Impossible de lire la valeur actuelle de la commande Statut.");
        }
        $config['status_home_value'] = (string) $value;
        self::saveConfig($eqLogic, $config);
        log::add('RoboProg', 'info', $config['robot_name'] . " : statut \"à la maison\" enregistré (\"$value\").");
        return $config['status_home_value'];
    }

    // Aperçu en direct d'une commande (utilisé par les icônes ✅/❌ à
    // côté de chaque champ). Ne JAMAIS exécuter une commande action ici
    // (juste confirmer qu'elle existe) : seules les commandes "info" ont
    // une valeur à prévisualiser.
    public static function previewValue($raw, $min = null, $max = null) {
        if ($raw === null || $raw === '') {
            return array('value' => null, 'valid' => null, 'error' => null);
        }
        $cmd = self::resolveCmd($raw);
        if (!is_object($cmd)) {
            return array('value' => null, 'valid' => false, 'error' => 'Commande introuvable');
        }
        if ($cmd->getType() != 'info') {
            return array('value' => 'commande action (existe)', 'valid' => true, 'error' => null);
        }
        $val = $cmd->execCmd();
        $valid = true;
        $error = null;
        if ($min !== null && $max !== null && $min !== '' && $max !== '') {
            if (!is_numeric($val) || $val < $min || $val > $max) {
                $valid = false;
                $error = "Hors limites ($min à $max)";
            }
        }
        return array('value' => $val, 'valid' => $valid, 'error' => $error);
    }

    /* ================================================================ */
    /* Aides diverses                                                    */
    /* ================================================================ */

    private static function isRobotHome($config) {
        if (empty($config['status_cmd_id']) || $config['status_home_value'] === null || $config['status_home_value'] === '') {
            return null; // indéterminable
        }
        $value = self::getCmdValue($config['status_cmd_id']);
        if ($value === null) {
            return null;
        }
        return ((string) $value) === ((string) $config['status_home_value']);
    }

    // Point d'entrée UNIQUE pour vérifier si les conditions de sécurité
    // (pluie, humidité, température, batterie) sont réunies. Utilisé
    // systématiquement avant CHAQUE envoi de commande Start ou Bordures,
    // y compris la relance après un rattrapage — pour ne jamais envoyer
    // deux vérifications différentes selon le point d'entrée.
    private static function allConditionsOk($config, $state = null) {
        if (self::isRainNow($config)) {
            return false;
        }
        // Délai post-pluie : après une interruption, on attend le délai
        // fixe configuré ET, si le statut est disponible, la confirmation
        // que le robot est bien rentré (plus fiable qu'un simple délai).
        if ($state !== null && !empty($state['rain_interrupt_until'])) {
            if (time() < $state['rain_interrupt_until']) {
                return false;
            }
            if (!empty($config['status_cmd_id']) && !empty($config['status_home_value']) && !self::isRobotHome($config)) {
                return false;
            }
        }
        if (!empty($config['humidity_cmd_id'])) {
            $hum = self::getCmdValue($config['humidity_cmd_id']);
            if ($hum === null || !is_numeric($hum) || floatval($hum) > floatval($config['humidity_threshold'])) {
                return false;
            }
            // Délai de confirmation : l'humidité doit être sous le seuil
            // depuis au moins humidity_duration_minutes. $state est
            // optionnel pour compat (les rares appels sans état
            // disponible ignorent cette porte, mais tous les points de
            // déclenchement réels de evaluate() passent bien $state).
            if ($state !== null) {
                $duration_needed = intval($config['humidity_duration_minutes']) * 60;
                if (empty($state['humidity_low_since']) || (time() - $state['humidity_low_since']) < $duration_needed) {
                    return false;
                }
            }
        }
        if (!empty($config['temperature_cmd_id'])) {
            $temp = self::getCmdValue($config['temperature_cmd_id']);
            if ($temp !== null && is_numeric($temp)) {
                if (floatval($temp) < floatval($config['temperature_min']) || floatval($temp) > floatval($config['temperature_max'])) {
                    return false;
                }
            }
        }
        if (!self::isGoodWeather($config)) {
            return false;
        }
        if (!self::isBatteryOk($config)) {
            return false;
        }
        return true;
    }

    // Familles de codes météo "temps sec/dégagé" — exactement les mêmes
    // plages que LandroidRTK (figées en dur, pas configurables).
    public static $GOOD_WEATHER_RANGES = array(
        array(800, 804),
        array(1000, 1009),
    );

    // Vérifie que la condition météo actuelle (code numérique) fait
    // partie de la liste figée ci-dessus. Même principe que LandroidRTK.
    // Emojis standards (indépendants de tout plugin météo), sélectionnés
    // à partir des familles de codes OpenWeatherMap ET WeatherAPI.com (les
    // deux sources supportées par le plugin météo générique). Un seul
    // emoji par grande famille (orage, bruine, pluie, neige/grésil) —
    // brume/poussière et tornade/vent violent ne sont volontairement pas
    // catégorisées (retombent sur l'inconnu).
    private static function getEmoji($condition_id) {
        if ($condition_id === null || $condition_id === '') {
            return '❔';
        }
        $id = intval($condition_id);
        if ($id == 800) return '☀️';
        if ($id == 801) return '🌤️';
        if ($id == 802) return '⛅';
        if ($id == 803) return '🌥️';
        if ($id == 804) return '☁️';
        if ($id == 1000) return '☀️';
        if ($id == 1003) return '🌤️';
        if ($id == 1006) return '⛅';
        if ($id == 1009) return '☁️';

        if ($id >= 200 && $id < 300) return '⛈️';
        if (in_array($id, array(1087, 1273, 1276, 1279, 1282))) return '⛈️';

        if ($id >= 300 && $id < 400) return '🌦️';
        if (in_array($id, array(1063, 1072, 1150, 1153, 1168))) return '🌦️';

        if ($id >= 500 && $id < 600) return '🌧️';
        if (in_array($id, array(1171, 1180, 1183, 1186, 1189, 1192, 1195, 1198, 1201, 1240, 1243, 1246))) return '🌧️';

        if ($id >= 600 && $id < 700) return '❄️';
        if (in_array($id, array(1066, 1069, 1114, 1117, 1204, 1207, 1210, 1213, 1216, 1219, 1222, 1225, 1237, 1249, 1252, 1255, 1258, 1261, 1264))) return '❄️';

        // --- Brume / brouillard / poussière / fumée ---
        if (in_array($id, array(701, 711, 721, 731, 741, 751, 761, 762))) return '😶‍🌫️';
        if (in_array($id, array(1012, 1015, 1018, 1030, 1033, 1036, 1039, 1042, 1045, 1048, 1135, 1147))) return '😶‍🌫️';

        // --- Tornade / vent violent ---
        if (in_array($id, array(771, 781))) return '🌪️';
        if (in_array($id, array(1021, 1024, 1027))) return '🌪️';

        return '❔';
    }

    // Construit les lignes météo/batterie standard (emoji+condition,
    // température, humidité, batterie) utilisées par tous les messages
    // de démarrage (tonte classique, bordures, bordures+enchaînement).
    // Chaque ligne n'est ajoutée que si la donnée correspondante est
    // configurée et disponible, exactement comme pour la notification
    // "TONTE" d'origine.
    private static function buildWeatherLines($config) {
        $lines = array();
        $condition_label = self::getCmdValue($config['condition_cmd_id']);
        if (!empty($condition_label)) {
            $emoji = self::getEmoji(self::getCmdValue($config['condition_id_cmd_id']));
            $lines[] = "$emoji $condition_label";
        }
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val = self::getCmdValue($config['temperature_cmd_id']);
            if ($temp_val !== null && is_numeric($temp_val)) {
                $lines[] = "🌡️ La température est de {$temp_val}°C.";
            }
        }
        if (!empty($config['humidity_cmd_id'])) {
            $humidity_val = self::getCmdValue($config['humidity_cmd_id']);
            if ($humidity_val !== null && is_numeric($humidity_val)) {
                $lines[] = "💧 L'humidité est de {$humidity_val}%.";
            }
        }
        if (!empty($config['battery_cmd_id'])) {
            $battery_val = self::getCmdValue($config['battery_cmd_id']);
            if ($battery_val !== null && is_numeric($battery_val)) {
                $lines[] = "🔋 Batterie : {$battery_val}%.";
            }
        }
        return $lines;
    }

    private static function isGoodWeather($config) {
        $condition_id = self::getCmdValue($config['condition_id_cmd_id']);
        if ($condition_id === null || $condition_id === '' || !is_numeric($condition_id)) {
            return false;
        }
        $id = intval($condition_id);
        foreach (self::$GOOD_WEATHER_RANGES as $range) {
            if ($id >= $range[0] && $id <= $range[1]) {
                return true;
            }
        }
        return false;
    }

    private static function isBatteryOk($config) {
        if (empty($config['battery_cmd_id'])) {
            return true; // pas de capteur lié : on ne bloque pas dessus
        }
        $value = self::getCmdValue($config['battery_cmd_id']);
        if ($value === null || !is_numeric($value)) {
            return true;
        }
        return floatval($value) >= floatval($config['battery_min_percent']);
    }

    private static function isRainNow($config) {
        if (self::isRainMatched($config['rain_cmd_id'], $config['rain_operator'], $config['rain_value'])) {
            return true;
        }
        if (self::isRainMatched($config['rain_extra_cmd_id'], $config['rain_extra_operator'], $config['rain_extra_value'])) {
            return true;
        }
        return false;
    }

    private static function isRainMatched($cmd_id, $operator, $expected_value) {
        if (empty($cmd_id)) {
            return false;
        }
        $cmd = self::resolveCmd($cmd_id);
        if (!is_object($cmd)) {
            return false;
        }
        $val = trim((string) $cmd->execCmd());
        $expected = trim((string) $expected_value);
        $is_numeric_cmp = is_numeric($val) && is_numeric($expected);
        $matches = $is_numeric_cmp ? (floatval($val) == floatval($expected)) : (strcasecmp($val, $expected) == 0);
        if ($operator == '!=') {
            $matches = !$matches;
        }
        return $matches;
    }

    private static function isWeekdayDue($edge_weekdays) {
        // 1 (lundi) .. 7 (dimanche), comme date('N').
        $today_n = (string) date('N');
        return is_array($edge_weekdays) && in_array($today_n, array_map('strval', $edge_weekdays));
    }

    // Cherche la prochaine date (à partir de $fromDate inclus) qui tombe
    // sur l'un des jours de semaine configurés pour les bordures.
    // Retourne null si aucun jour n'est configuré.
    private static function findNextWeekday($edge_weekdays, $fromDate) {
        if (!is_array($edge_weekdays) || empty($edge_weekdays)) {
            return null;
        }
        $wanted = array_map('strval', $edge_weekdays);
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($fromDate . " +$i days"));
            if (in_array((string) date('N', strtotime($d)), $wanted)) {
                return $d;
            }
        }
        return null;
    }

    // Estime la date de la PROCHAINE tonte (classique et/ou bordures) et
    // son type, pour affichage dans la notification "PAS DE TONTE".
    // Volontairement simplifié par rapport à estimateNextMow() : ne
    // cherche que le jour et le type, pas une heure précise ni les cas
    // "indéterminé" liés à l'humidité en cours de stabilisation.
    private static function estimateNextMowDateType($eqLogic, $config) {
        $state = self::getState($eqLogic);
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Prochaine date où l'espacement de la tonte classique sera respecté.
        if (!empty($state['last_mow_date'])) {
            $next_classic_date = date('Y-m-d', strtotime($state['last_mow_date'] . " +{$config['spacing_days']} days"));
        } else {
            $next_classic_date = $today;
        }
        // Cette estimation est utilisée en fin de journée (fenêtre déjà
        // fermée) : la prochaine tentative ne peut de toute façon pas
        // être avant demain.
        if ($next_classic_date <= $today) {
            $next_classic_date = $tomorrow;
        }

        // Prochaine date de coupe des bordures, le cas échéant.
        $edge_available = !empty($config['edge_cmd_id']);
        $next_edge_date = null;
        if ($edge_available) {
            if (!empty($state['edge_catchup_pending']) && $config['edge_catchup_enabled'] == '1') {
                // Le rattrapage ne se fait que le jour où la classique est
                // de toute façon due : même date que $next_classic_date.
                $next_edge_date = $next_classic_date;
            } elseif ($config['edge_mode'] == 'weekday') {
                $next_edge_date = self::findNextWeekday($config['edge_weekdays'], $tomorrow);
            } else {
                $base = !empty($state['last_edge_date']) ? $state['last_edge_date'] : $today;
                $candidate = date('Y-m-d', strtotime($base . " +{$config['edge_interval_days']} days"));
                $next_edge_date = ($candidate <= $today) ? $tomorrow : $candidate;
            }
        }

        if ($next_edge_date !== null && $next_edge_date == $next_classic_date) {
            $type = ($config['edge_resume_enabled'] == '1') ? 'BORDURES + TONTE' : 'BORDURES';
            return array('date' => $next_classic_date, 'type' => $type);
        }
        if ($next_edge_date !== null && $next_edge_date < $next_classic_date) {
            return array('date' => $next_edge_date, 'type' => 'BORDURES');
        }
        return array('date' => $next_classic_date, 'type' => 'TONTE');
    }

    /* ================================================================ */
    /* Notifications (mêmes conventions que LandroidRTK : filtre par     */
    /* type + case à cocher par destinataire)                            */
    /* ================================================================ */

    public static function sendNotifications($config, $title, $message_html, $message_plain, $type = 'default') {
        if (empty($config['notifications']) || !is_array($config['notifications'])) {
            return;
        }
        foreach ($config['notifications'] as $notif) {
            if (empty($notif['cmd_id'])) {
                continue;
            }
            if ($type == 'no_mow' && isset($notif['notify_no_mow']) && $notif['notify_no_mow'] == '0') {
                continue;
            }
            if ($type == 'error' && (!isset($notif['notify_error']) || $notif['notify_error'] != '1')) {
                continue;
            }
            if ($type == 'edge_catchup' && (!isset($notif['notify_edge_catchup']) || $notif['notify_edge_catchup'] != '1')) {
                continue;
            }
            if ($type == 'edge_resume' && (!isset($notif['notify_edge_resume']) || $notif['notify_edge_resume'] != '1')) {
                continue;
            }
            $cmd = self::resolveCmd($notif['cmd_id']);
            if (!is_object($cmd)) {
                continue;
            }
            $use_html = !empty($notif['html']) && $notif['html'] == '1';
            $final_title = !empty($notif['title']) ? $notif['title'] : $title;
            $final_message = $use_html ? $message_html : $message_plain;
            try {
                $cmd->execCmd(array('title' => $final_title, 'message' => $final_message));
            } catch (\Throwable $e) {
                log::add('RoboProg', 'error', 'Échec envoi notification cmd ' . $notif['cmd_id'] . ' : ' . $e->getMessage());
            }
        }
    }

    /* ================================================================ */
    /* État des conditions (pour affichage type "tableau" côté UI)       */
    /* ================================================================ */

    public static function getConditionsStatus($eqLogic, $config) {
        $rows = array();
        $all_ok = true;
        $today = date('Y-m-d');
        $state = self::getState($eqLogic);

        $add = function ($label, $ok, $detail) use (&$rows, &$all_ok) {
            $rows[] = array('label' => $label, 'ok' => $ok, 'detail' => $detail);
            if (!$ok) {
                $all_ok = false;
            }
        };

        $already = ($state['last_mow_date'] === $today);
        $add('Pas déjà tondu aujourd\'hui', !$already, $already ? "Déjà fait" : "OK");

        // Ligne "Statut" combinée : priorité à l'état pluie (le plus
        // bloquant), puis tonte/bordures en cours.
        $mow_duration_seconds = max(60, intval($config['mow_duration_minutes']) * 60);
        $status_detail = null;
        if (self::isRainNow($config)) {
            $status_detail = "Pluie en cours — pas de redémarrage tant qu'il pleut";
        } elseif (!empty($state['rain_interrupt_until']) && time() < $state['rain_interrupt_until']) {
            $remaining_min = ceil(($state['rain_interrupt_until'] - time()) / 60);
            $status_detail = "En attente du délai après pluie (encore {$remaining_min} min)";
        } elseif (!empty($state['rain_interrupt_until']) && !empty($config['status_cmd_id']) && !empty($config['status_home_value']) && !self::isRobotHome($config)) {
            $status_detail = "Délai après pluie écoulé, en attente de la confirmation du retour à la maison (statut actuel : " . (self::getCmdValue($config['status_cmd_id']) ?? '?') . ")";
        } elseif (!empty($state['current_mow_started_at']) && (time() - $state['current_mow_started_at']) <= $mow_duration_seconds) {
            $remaining_min = ceil(($mow_duration_seconds - (time() - $state['current_mow_started_at'])) / 60);
            $status_detail = "Tonte en cours (encore ~{$remaining_min} min avant fin estimée)";
        } elseif (!empty($state['current_edge_started_at']) && (time() - $state['current_edge_started_at']) <= $mow_duration_seconds) {
            $remaining_min = ceil(($mow_duration_seconds - (time() - $state['current_edge_started_at'])) / 60);
            $status_detail = "Tonte des bordures en cours (encore ~{$remaining_min} min avant fin estimée)";
        }
        if ($status_detail !== null) {
            $rows[] = array('label' => 'Statut', 'ok' => true, 'detail' => $status_detail);
        }

        $rows[] = array('label' => 'Tonte prévue aujourd\'hui', 'ok' => true, 'detail' => self::estimateNextMowShort($eqLogic, $config));

        // L'espacement (jours entre deux tontes CLASSIQUES) ne bloque
        // rien un jour de bordures : les bordures ont leur propre
        // calendrier indépendant. On le calcule ici pour ne pas afficher
        // à tort un "Non" qui donnerait l'impression que rien ne va se
        // passer aujourd'hui, alors que les bordures (et éventuellement
        // la tonte classique juste après) vont bien démarrer.
        $edge_available = !empty($config['edge_cmd_id']);
        $edge_due_today = false;
        if ($edge_available && $state['last_edge_date'] !== $today) {
            if ($config['edge_mode'] == 'weekday') {
                $edge_due_today = self::isWeekdayDue($config['edge_weekdays']);
            } else {
                if (empty($state['last_edge_date'])) {
                    $edge_due_today = true;
                } else {
                    $diff_edge = (strtotime($today) - strtotime($state['last_edge_date'])) / 86400;
                    $edge_due_today = ($diff_edge >= intval($config['edge_interval_days']));
                }
            }
        }
        $edge_covers_today = $edge_due_today || (!empty($state['edge_catchup_pending']) && $config['edge_catchup_enabled'] == '1');

        if (!empty($state['last_mow_date'])) {
            $diff_days = (strtotime($today) - strtotime($state['last_mow_date'])) / 86400;
            $ok = $diff_days >= intval($config['spacing_days']);
            if (!$ok && $edge_covers_today) {
                $add('Espacement jours de tontes respecté', true, "Non requis aujourd'hui — couvert par les bordures (" . intval($diff_days) . " jour(s) depuis la dernière tonte classique, min. " . $config['spacing_days'] . ")");
            } else {
                $add('Espacement jours de tontes respecté', $ok, $ok ? "OK" : (intval($diff_days) . " jour(s) depuis la dernière tonte (min. " . $config['spacing_days'] . ")"));
            }
        } else {
            $add('Espacement jours de tontes respecté', true, "Aucune tonte enregistrée");
        }

        $rain = self::isRainNow($config);
        $add('Aucune pluie détectée actuellement', !$rain, $rain ? "Pluie en cours" : "OK");

        if (!empty($config['humidity_cmd_id'])) {
            $hum = self::getCmdValue($config['humidity_cmd_id']);
            $ok = ($hum !== null && is_numeric($hum) && floatval($hum) <= floatval($config['humidity_threshold']));
            $duration_needed = intval($config['humidity_duration_minutes']) * 60;
            $wait_ok = true;
            $wait_detail = '';
            if ($ok) {
                $elapsed = !empty($state['humidity_low_since']) ? (time() - $state['humidity_low_since']) : 0;
                $wait_ok = ($elapsed >= $duration_needed);
                if (!$wait_ok) {
                    $remaining_min = max(0, ceil(($duration_needed - $elapsed) / 60));
                    $wait_detail = " — doit encore rester {$remaining_min} min sous ce seuil";
                }
            }
            $add('Humidité sous le seuil (délai inclus)', $ok && $wait_ok, ($hum !== null ? "$hum%" : "?") . " (seuil " . $config['humidity_threshold'] . "%)" . $wait_detail);
        }

        if (!empty($config['condition_id_cmd_id'])) {
            $cond_label = self::getCmdValue($config['condition_cmd_id']);
            $ok = self::isGoodWeather($config);
            $add('Condition météo acceptée', $ok, ($cond_label !== null && $cond_label !== '' ? (string) $cond_label : "?"));
        }

        if (!empty($config['temperature_cmd_id'])) {
            $temp = self::getCmdValue($config['temperature_cmd_id']);
            if ($temp !== null && is_numeric($temp)) {
                $add('Température ≥ seuil minimum (' . $config['temperature_min'] . '°C)', floatval($temp) >= floatval($config['temperature_min']), "{$temp}°C");
                $add('Température ≤ seuil maximum (' . $config['temperature_max'] . '°C)', floatval($temp) <= floatval($config['temperature_max']), "{$temp}°C");
            }
        }

        if (!empty($config['battery_cmd_id'])) {
            $batt = self::getCmdValue($config['battery_cmd_id']);
            $ok = ($batt !== null && is_numeric($batt) && floatval($batt) >= floatval($config['battery_min_percent']));
            $add('Batterie suffisante (min. ' . $config['battery_min_percent'] . '%)', $ok, ($batt !== null ? "$batt%" : "?"));
        }

        return array('rows' => $rows, 'all_ok' => $all_ok);
    }

    /* ================================================================ */
    /* Cœur de la logique : appelé toutes les 5 min par le cron          */
    /* ================================================================ */

    public static function evaluate($eqLogic) {
        $config = self::getConfig($eqLogic);
        self::syncWidgetCommands($eqLogic); // garde le widget à jour même si désactivé
        if ($config['enabled'] != '1') {
            return;
        }
        if (empty($config['start_cmd_id'])) {
            return;
        }
        $checked = self::checkConfig($config);
        if ($checked !== true) {
            return; // configuration incomplète/invalide : on ne prend aucun risque
        }

        $state = self::getState($eqLogic);
        $today = date('Y-m-d');

        // -------------------------------------------------------------
        // 1) Si on attend le retour du robot après des bordures de
        //    rattrapage, avant de relancer le cycle classique.
        // -------------------------------------------------------------
        if ($state['cycle_phase'] == 'edge_in_progress') {
            // Sécurité anti-blocage : on abandonne l'attente après 4h.
            if (!empty($state['cycle_phase_since']) && (time() - intval($state['cycle_phase_since'])) > 4 * 3600) {
                log::add('RoboProg', 'warning', $config['robot_name'] . " : abandon de l'attente de retour après bordures (délai de sécurité dépassé).");
                $state['cycle_phase'] = null;
                $state['cycle_phase_since'] = null;
                self::saveState($eqLogic, $state);
                return;
            }
            $home = self::isRobotHome($config);
            if ($home === true && self::isRainNow($config)) {
                // Le robot est rentré, mais il pleut : ses bordures de
                // rattrapage ont très probablement été interrompues en
                // cours de route plutôt que terminées normalement. On
                // NE relance PAS la tonte classique, et on reprogramme
                // un nouveau rattrapage de bordures pour plus tard.
                $state['cycle_phase'] = null;
                $state['cycle_phase_since'] = null;
                $state['edge_catchup_pending'] = true;
                self::saveState($eqLogic, $state);
                log::add('RoboProg', 'warning', $config['robot_name'] . " : retour à la maison sous la pluie pendant les bordures — probablement interrompues, rattrapage programmé.");
                $name = strtoupper($config['robot_name']);
                $text = "🌧️✂️ {$config['robot_name']} est rentré sous la pluie pendant les bordures (probablement interrompues) : un rattrapage est programmé, la tonte classique n'est pas relancée.";
                self::sendNotifications($config, "$name - RATTRAPAGE INTERROMPU", $text, $text, 'edge_catchup');
                return;
            }
            if ($home === true && self::allConditionsOk($config, $state)) {
                $start_cmd = self::resolveCmd($config['start_cmd_id']);
                if (is_object($start_cmd)) {
                    $start_cmd->execCmd();
                    $state['cycle_phase'] = null;
                    $state['cycle_phase_since'] = null;
                    $state['last_mow_date'] = $today;
                    $state['current_mow_started_at'] = time();
                    $state['rain_interrupt_until'] = null;
                    self::saveState($eqLogic, $state);
                    log::add('RoboProg', 'info', $config['robot_name'] . " : bordures terminées, relance du cycle de tonte classique.");
                    $name = strtoupper($config['robot_name']);
                    $text = "▶️🔁 {$config['robot_name']} a terminé les bordures et repart pour la tonte classique.";
                    self::sendNotifications($config, "$name - RELANCE TONTE", $text, $text, 'edge_resume');
                }
            }
            return; // tant qu'on attend, on ne fait rien d'autre ce tour-ci
        }

        // -------------------------------------------------------------
        // 1bis) Sécurité pluie : si un capteur pluie est configuré et
        // qu'il pleut, on renvoie le robot à la maison (si une commande
        // de retour est configurée) et on invalide la tonte/coupe de
        // bordures en cours si elle a débuté récemment (dans la durée de
        // tonte estimée) — sinon on considère qu'il s'agit d'une fausse
        // alerte, sans rapport avec une tonte déjà terminée depuis
        // longtemps (cas des robots sans garage, où le capteur peut se
        // déclencher des heures après la fin réelle de la tonte).
        // Sans capteur pluie ET sans commande de retour à la maison
        // configurés, ce bloc ne fait simplement rien (comportement
        // volontaire : pas de gestion de la pluie dans ce cas).
        // -------------------------------------------------------------
        if (self::isRainNow($config)) {
            $mow_duration_seconds = max(60, intval($config['mow_duration_minutes']) * 60);
            $during_mow = !empty($state['current_mow_started_at']) && (time() - $state['current_mow_started_at']) <= $mow_duration_seconds;
            $during_edge = !empty($state['current_edge_started_at']) && (time() - $state['current_edge_started_at']) <= $mow_duration_seconds;

            // Avant d'envoyer la commande de retour, on vérifie si on sait
            // que le robot est déjà à la maison (via status_cmd_id +
            // status_home_value, si configurés) — évite de solliciter le
            // robot/l'API pour rien un jour sans tonte où il est déjà
            // rentré. Si l'info n'est pas configurée/déterminable
            // (isRobotHome() renvoie null), on envoie quand même la
            // commande par sécurité (comportement inchangé dans ce cas).
            if (!empty($config['home_cmd_id']) && self::isRobotHome($config) !== true) {
                $home_cmd = self::resolveCmd($config['home_cmd_id']);
                if (is_object($home_cmd)) {
                    $home_cmd->execCmd();
                }
            }

            if ($during_mow) {
                $state['last_mow_date'] = null;
                $state['rain_interrupt_until'] = time() + (intval($config['rain_interrupt_minutes']) * 60);
                $state['current_mow_started_at'] = null;
                self::saveState($eqLogic, $state);
                log::add('RoboProg', 'warning', $config['robot_name'] . " : pluie détectée pendant la tonte — retour à la maison demandé (si commande configurée), tonte invalidée et retentée plus tard.");
                $name = strtoupper($config['robot_name']);
                $text = "🌧️ {$config['robot_name']} rentre à la maison à cause de la pluie. La tonte en cours est annulée et sera retentée plus tard.";
                self::sendNotifications($config, "$name - PLUIE", $text, $text);
            } elseif ($during_edge) {
                $state['current_edge_started_at'] = null;
                $state['edge_catchup_pending'] = true;
                self::saveState($eqLogic, $state);
                log::add('RoboProg', 'warning', $config['robot_name'] . " : pluie détectée pendant les bordures — retour à la maison demandé, rattrapage programmé.");
                $name = strtoupper($config['robot_name']);
                $text = "🌧️✂️ {$config['robot_name']} rentre à la maison à cause de la pluie pendant les bordures. Un rattrapage est programmé.";
                self::sendNotifications($config, "$name - PLUIE", $text, $text, 'edge_catchup');
            }
            return;
        }

        // Pas de pluie actuellement : si une tonte ou une coupe de
        // bordures a dépassé sa durée de tonte estimée sans interruption,
        // on considère qu'elle s'est terminée normalement (nettoyage de
        // l'indicateur "en cours").
        if (!empty($state['current_mow_started_at']) || !empty($state['current_edge_started_at'])) {
            $mow_duration_seconds = max(60, intval($config['mow_duration_minutes']) * 60);
            $changed = false;
            if (!empty($state['current_mow_started_at']) && (time() - $state['current_mow_started_at']) > $mow_duration_seconds) {
                $state['current_mow_started_at'] = null;
                $changed = true;
            }
            if (!empty($state['current_edge_started_at']) && (time() - $state['current_edge_started_at']) > $mow_duration_seconds) {
                $state['current_edge_started_at'] = null;
                $changed = true;
            }
            if ($changed) {
                self::saveState($eqLogic, $state);
            }
        }

        // -------------------------------------------------------------
        // 2) Déjà tondu aujourd'hui : rien à faire.
        // -------------------------------------------------------------
        if ($state['last_mow_date'] === $today) {
            return;
        }

        // -------------------------------------------------------------
        // 2bis) Suivi du délai d'humidité (humidity_low_since).
        // IMPORTANT : doit tourner à CHAQUE cycle, quel que soit
        // l'espacement ou la plage horaire — sinon, si l'humidité repasse
        // au-dessus du seuil un jour où on ne regarde pas, le compteur ne
        // serait jamais remis à zéro et resterait périmé.
        // -------------------------------------------------------------
        $humidity_val_tracking = self::getCmdValue($config['humidity_cmd_id']);
        $threshold_tracking = floatval($config['humidity_threshold']);
        if (!is_numeric($humidity_val_tracking) || floatval($humidity_val_tracking) > $threshold_tracking) {
            if ($state['humidity_low_since'] !== null) {
                $state['humidity_low_since'] = null;
                self::saveState($eqLogic, $state);
            }
        } elseif ($state['humidity_low_since'] === null) {
            $state['humidity_low_since'] = time();
            self::saveState($eqLogic, $state);
        }

        // -------------------------------------------------------------
        // 3) Vérification de la plage horaire.
        // -------------------------------------------------------------
        $start_min = self::resolveTimeMinutes($config['time_start_cmd_id']);
        $end_min = self::resolveTimeMinutes($config['time_end_cmd_id']);
        if ($start_min === null || $end_min === null) {
            log::add('RoboProg', 'error', $config['robot_name'] . " : impossible de déterminer la plage horaire (heures invalides).");
            return;
        }
        $latest_start = $end_min - intval($config['margin_minutes']);
        $now_minutes = intval(date('H')) * 60 + intval(date('i'));
        $in_window = ($now_minutes >= $start_min && $now_minutes <= $latest_start);

        // -------------------------------------------------------------
        // 4) Espacement (tonte classique).
        // -------------------------------------------------------------
        $spacing_ok = true;
        if (!empty($state['last_mow_date'])) {
            $diff_days = (strtotime($today) - strtotime($state['last_mow_date'])) / 86400;
            $spacing_ok = ($diff_days >= intval($config['spacing_days']));
        }

        // -------------------------------------------------------------
        // 5) Bordures : est-ce dû aujourd'hui, et rattrapage en attente ?
        // -------------------------------------------------------------
        $edge_available = !empty($config['edge_cmd_id']);
        $edge_due_today = false;
        if ($edge_available && $state['last_edge_date'] !== $today) {
            if ($config['edge_mode'] == 'weekday') {
                $edge_due_today = self::isWeekdayDue($config['edge_weekdays']);
            } else {
                if (empty($state['last_edge_date'])) {
                    $edge_due_today = true;
                } else {
                    $diff_edge = (strtotime($today) - strtotime($state['last_edge_date'])) / 86400;
                    $edge_due_today = ($diff_edge >= intval($config['edge_interval_days']));
                }
            }
        }

        if (!$in_window) {
            // Fin de journée : si les bordures étaient dues et pas faites,
            // on marque un rattrapage en attente pour la prochaine fois.
            if ($now_minutes > $latest_start && $edge_due_today && $config['edge_catchup_enabled'] == '1' && !$state['edge_catchup_pending']) {
                $state['edge_catchup_pending'] = true;
                self::saveState($eqLogic, $state);
                log::add('RoboProg', 'info', $config['robot_name'] . " : bordures dues aujourd'hui mais non faites, rattrapage programmé.");
            }
            self::notifyNotReadyIfNeeded($eqLogic, $config, $state, $today, $now_minutes, $latest_start, $spacing_ok);
            return;
        }

        // -------------------------------------------------------------
        // 6) Conditions météo/sécurité communes (mêmes seuils pour
        //    tonte classique ET bordures), via la fonction unique
        //    partagée avec la relance après rattrapage (voir plus haut).
        // -------------------------------------------------------------
        if (!self::allConditionsOk($config, $state)) {
            return;
        }

        // -------------------------------------------------------------
        // 7) Décision : bordures (rattrapage) ou tonte classique.
        //    Le rattrapage ne se substitue QUE le jour où une tonte
        //    classique était de toute façon prévue (espacement respecté).
        // -------------------------------------------------------------
        if ($edge_available && $config['edge_catchup_enabled'] == '1' && $state['edge_catchup_pending'] && $spacing_ok) {
            $edge_cmd = self::resolveCmd($config['edge_cmd_id']);
            if (!is_object($edge_cmd)) {
                return;
            }
            $edge_cmd->execCmd();
            $state['edge_catchup_pending'] = false;
            $state['last_edge_date'] = $today;
            $state['current_edge_started_at'] = time();
            $state['rain_interrupt_until'] = null;
            if ($config['edge_resume_enabled'] == '1' && !empty($config['home_cmd_id']) && !empty($config['status_cmd_id'])) {
                $state['cycle_phase'] = 'edge_in_progress';
                $state['cycle_phase_since'] = time();
            } else {
                // Pas de relance automatique : ce jour est "consommé" par
                // les bordures, la tonte classique reprendra à son
                // prochain jour normal.
                $state['last_mow_date'] = $today;
            }
            self::saveState($eqLogic, $state);
            log::add('RoboProg', 'info', $config['robot_name'] . " : rattrapage des bordures déclenché.");
            $name = strtoupper($config['robot_name']);
            $text = "✂️🔁 {$config['robot_name']} effectue un rattrapage de bordures (dernier passage manqué).";
            self::sendNotifications($config, "$name - RATTRAPAGE BORDURES", $text, $text, 'edge_catchup');
            return;
        }

        if ($edge_available && $edge_due_today) {
            // Jour de bordures normal (pas un rattrapage) : on le
            // déclenche directement à la place de la tonte du jour,
            // que ce soit en mode "jours fixes" ou "intervalle".
            $edge_cmd = self::resolveCmd($config['edge_cmd_id']);
            if (is_object($edge_cmd)) {
                $edge_cmd->execCmd();
                $state['last_edge_date'] = $today;
                $state['current_edge_started_at'] = time();
                $state['rain_interrupt_until'] = null;
                $state['edge_catchup_pending'] = false; // ce passage couvre aussi un éventuel rattrapage resté en attente
                if ($config['edge_resume_enabled'] == '1' && !empty($config['home_cmd_id']) && !empty($config['status_cmd_id'])) {
                    // Enchaînement sur une tonte classique demandé, même
                    // pour un jour de bordures "normal" (pas un
                    // rattrapage) : on attend le retour du robot avant de
                    // relancer, exactement comme pour un rattrapage.
                    $state['cycle_phase'] = 'edge_in_progress';
                    $state['cycle_phase_since'] = time();
                }
                self::saveState($eqLogic, $state);
                log::add('RoboProg', 'info', $config['robot_name'] . " : coupe des bordures programmée du jour déclenchée.");
                $name = strtoupper($config['robot_name']);
                if ($config['edge_resume_enabled'] == '1') {
                    // Bordures + enchaînement prévu sur la tonte classique :
                    // message distinct de "bordures seules", annonçant
                    // clairement l'enchaînement, avec les mêmes infos
                    // météo/batterie que la notification de tonte classique.
                    $msg_parts = array_merge(
                        array("✂️🔁 {$config['robot_name']} va d'abord couper les bordures, puis enchaînera sur la tonte classique."),
                        self::buildWeatherLines($config)
                    );
                    $msg_html = implode('<br/>', $msg_parts);
                    $msg_plain = implode("\n", $msg_parts);
                    self::sendNotifications($config, "$name - BORDURES + TONTE", $msg_html, $msg_plain);
                } else {
                    // Bordures seules, pas d'enchaînement prévu.
                    $msg_parts = array_merge(
                        array("✂️ {$config['robot_name']} va couper les bordures."),
                        self::buildWeatherLines($config)
                    );
                    $msg_html = implode('<br/>', $msg_parts);
                    $msg_plain = implode("\n", $msg_parts);
                    self::sendNotifications($config, "$name - BORDURES", $msg_html, $msg_plain);
                }
                return;
            }
        }

        if ($spacing_ok) {
            $start_cmd = self::resolveCmd($config['start_cmd_id']);
            if (is_object($start_cmd)) {
                $msg_parts = array_merge(
                    array("✂️ {$config['robot_name']} va tondre la pelouse."),
                    self::buildWeatherLines($config)
                );
                $msg_html = implode('<br/>', $msg_parts);
                $msg_plain = implode("\n", $msg_parts);
                $name = strtoupper($config['robot_name']);
                self::sendNotifications($config, "$name - TONTE", $msg_html, $msg_plain);

                $start_cmd->execCmd();
                $state['last_mow_date'] = $today;
                $state['current_mow_started_at'] = time();
                $state['rain_interrupt_until'] = null;
                self::saveState($eqLogic, $state);
                log::add('RoboProg', 'info', $config['robot_name'] . " : tonte classique déclenchée.");
            }
        }
    }

    private static function resolveTimeMinutes($cmd_id) {
        if (empty($cmd_id)) {
            return null;
        }
        $value = self::getCmdValue($cmd_id);
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if (!ctype_digit($value)) {
            return null;
        }
        $len = strlen($value);
        if ($len == 3) {
            $h = intval(substr($value, 0, 1));
            $m = intval(substr($value, 1, 2));
        } elseif ($len == 4) {
            $h = intval(substr($value, 0, 2));
            $m = intval(substr($value, 2, 2));
        } else {
            return null;
        }
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }
        return $h * 60 + $m;
    }

    // Notification de fin de journée si la tonte classique n'a pas pu
    // avoir lieu (mêmes principes que LandroidRTK : une fois la fenêtre
    // fermée, une seule fois par jour ; même structure multi-lignes que
    // la notification de démarrage — annonce → météo → température →
    // humidité → batterie).
    private static function notifyNotReadyIfNeeded($eqLogic, $config, $state, $today, $now_minutes, $latest_start, $spacing_ok) {
        if ($now_minutes <= $latest_start) {
            return; // fenêtre encore ouverte
        }
        if ($spacing_ok === false) {
            $reason = 'spacing';
        } elseif (self::isRainNow($config)) {
            $reason = 'rain';
        } else {
            $reason = 'weather';
        }
        if ($state['last_notification_reason'] == $reason && $state['last_notification_date'] == $today) {
            return;
        }
        $name = strtoupper($config['robot_name']);
        $labels = array(
            'spacing' => "pas encore le jour prévu (espacement de {$config['spacing_days']} jours)",
            'rain' => "pluie détectée",
            'weather' => "conditions météo/sécurité non réunies",
        );

        $msg_parts = array("💤 {$config['robot_name']} ne tondra pas aujourd'hui : {$labels[$reason]}.");

        $next = self::estimateNextMowDateType($eqLogic, $config);
        if ($next['date'] !== null) {
            $msg_parts[] = "📅 Prochaine tonte prévue le " . date('d/m/Y', strtotime($next['date'])) . " ({$next['type']}).";
        }

        if (!empty($config['condition_cmd_id'])) {
            $condition_label = self::getCmdValue($config['condition_cmd_id']);
            if (!empty($condition_label)) {
                $emoji = self::getEmoji(self::getCmdValue($config['condition_id_cmd_id']));
                $msg_parts[] = "$emoji Condition météo actuelle : $condition_label";
            }
        }
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val = self::getCmdValue($config['temperature_cmd_id']);
            if ($temp_val !== null && is_numeric($temp_val)) {
                $msg_parts[] = "🌡️ La température est actuellement de {$temp_val}°C";
            }
        }
        if (!empty($config['humidity_cmd_id'])) {
            $humidity_val = self::getCmdValue($config['humidity_cmd_id']);
            if ($humidity_val !== null && is_numeric($humidity_val)) {
                $msg_parts[] = "💧 L'humidité actuelle est de {$humidity_val}%";
            }
        }
        if (!empty($config['battery_cmd_id'])) {
            $battery_val = self::getCmdValue($config['battery_cmd_id']);
            if ($battery_val !== null && is_numeric($battery_val)) {
                $msg_parts[] = "🔋 La batterie est actuellement de {$battery_val}%";
            }
        }

        $msg_html = implode('<br/>', $msg_parts);
        $msg_plain = implode("\n", $msg_parts);
        self::sendNotifications($config, "$name - PAS DE TONTE", $msg_html, $msg_plain, 'no_mow');
        $state['last_notification_reason'] = $reason;
        $state['last_notification_date'] = $today;
        self::saveState($eqLogic, $state);
    }

    public static function getApiKey() {
        // Auto-génération si absente (ex: plugin déjà installé avant
        // l'ajout de ce mécanisme, sans repasser par install.php).
        $key = config::byKey('api', 'RoboProg', '');
        if ($key == '') {
            $key = config::genKey();
            config::save('api', $key, 'RoboProg');
            config::save('api::RoboProg::mode', 'localhost');
            config::save('api::RoboProg::restricted', 1);
        }
        return $key;
    }

    private static function applyWidgetScale($cmd, $scale = 0.5) {
        $params = $cmd->getDisplay('parameters');
        if (!is_array($params)) {
            $params = array();
        }
        $params['scale'] = $scale;
        $cmd->setDisplay('parameters', $params);
        $cmd->save();
    }

    // Widget "Programmation" (identique dans l'esprit à LandroidRTK) :
    // Activer/Désactiver, prochaine tonte estimée, curseurs réglables,
    // et — spécifique à RoboProg — le nom du robot + son statut courant
    // (si la commande Statut est liée), puisqu'il n'y a pas d'équipement
    // robot dédié séparé comme pour LandroidRTK.
    public static function syncWidgetCommands($eqLogic) {
        $config = self::getConfig($eqLogic);

        $cmd_robot_name = $eqLogic->getCmd(null, 'robot_name');
        if (!is_object($cmd_robot_name)) {
            $cmd_robot_name = new RoboProgCmd();
            $cmd_robot_name->setLogicalId('robot_name');
            $cmd_robot_name->setEqLogic_id($eqLogic->getId());
        }
        $cmd_robot_name->setName('Robot');
        $cmd_robot_name->setType('info');
        $cmd_robot_name->setSubType('string');
        $cmd_robot_name->setOrder(0);
        $cmd_robot_name->setDisplay('forceReturnLineAfter', '1');
        $cmd_robot_name->save();
        self::applyWidgetScale($cmd_robot_name);
        $eqLogic->checkAndUpdateCmd('robot_name', $config['robot_name']);

        if (!empty($config['status_cmd_id'])) {
            $cmd_robot_status = $eqLogic->getCmd(null, 'robot_status');
            if (!is_object($cmd_robot_status)) {
                $cmd_robot_status = new RoboProgCmd();
                $cmd_robot_status->setLogicalId('robot_status');
                $cmd_robot_status->setEqLogic_id($eqLogic->getId());
            }
            $cmd_robot_status->setName('Statut');
            $cmd_robot_status->setType('info');
            $cmd_robot_status->setSubType('string');
            $cmd_robot_status->setOrder(1);
            $cmd_robot_status->setDisplay('forceReturnLineAfter', '1');
            $cmd_robot_status->save();
            self::applyWidgetScale($cmd_robot_status);
            $status_val = self::getCmdValue($config['status_cmd_id']);
            $eqLogic->checkAndUpdateCmd('robot_status', $status_val !== null ? $status_val : '');
        }

        // Dates des derniers passages (avant Programmation/Prochaine tonte)
        $cmd_last_mow = $eqLogic->getCmd(null, 'last_mow_date');
        if (!is_object($cmd_last_mow)) {
            $cmd_last_mow = new RoboProgCmd();
            $cmd_last_mow->setLogicalId('last_mow_date');
            $cmd_last_mow->setEqLogic_id($eqLogic->getId());
        }
        $cmd_last_mow->setName('Dernière tonte');
        $cmd_last_mow->setType('info');
        $cmd_last_mow->setSubType('string');
        $cmd_last_mow->setOrder(28);
        $cmd_last_mow->setDisplay('forceReturnLineAfter', '1');
        $cmd_last_mow->save();
        self::applyWidgetScale($cmd_last_mow);
        $state_for_widget = self::getState($eqLogic);
        $eqLogic->checkAndUpdateCmd('last_mow_date', !empty($state_for_widget['last_mow_date']) ? date('d/m/Y', strtotime($state_for_widget['last_mow_date'])) : 'Jamais');

        if (!empty($config['edge_cmd_id'])) {
            $cmd_last_edge = $eqLogic->getCmd(null, 'last_edge_date');
            if (!is_object($cmd_last_edge)) {
                $cmd_last_edge = new RoboProgCmd();
                $cmd_last_edge->setLogicalId('last_edge_date');
                $cmd_last_edge->setEqLogic_id($eqLogic->getId());
            }
            $cmd_last_edge->setName('Dernière tonte bordures');
            $cmd_last_edge->setType('info');
            $cmd_last_edge->setSubType('string');
            $cmd_last_edge->setOrder(29);
            $cmd_last_edge->setDisplay('forceReturnLineAfter', '1');
            $cmd_last_edge->save();
            self::applyWidgetScale($cmd_last_edge);
            $eqLogic->checkAndUpdateCmd('last_edge_date', !empty($state_for_widget['last_edge_date']) ? date('d/m/Y', strtotime($state_for_widget['last_edge_date'])) : 'Jamais');
        }

        // Programmation : Oui/Non + boutons Activer/Désactiver
        $cmd_enabled = $eqLogic->getCmd(null, 'schedule_enabled');
        if (!is_object($cmd_enabled)) {
            $cmd_enabled = new RoboProgCmd();
            $cmd_enabled->setLogicalId('schedule_enabled');
            $cmd_enabled->setEqLogic_id($eqLogic->getId());
        }
        $cmd_enabled->setName('Programmation');
        $cmd_enabled->setType('info');
        $cmd_enabled->setSubType('binary');
        $cmd_enabled->setOrder(30);
        $cmd_enabled->setDisplay('forceReturnLineAfter', '1');
        $cmd_enabled->save();
        self::applyWidgetScale($cmd_enabled);
        $eqLogic->checkAndUpdateCmd('schedule_enabled', $config['enabled'] == '1' ? 1 : 0);

        $cmd_activate = $eqLogic->getCmd(null, 'schedule_activate');
        if (!is_object($cmd_activate)) {
            $cmd_activate = new RoboProgCmd();
            $cmd_activate->setLogicalId('schedule_activate');
            $cmd_activate->setEqLogic_id($eqLogic->getId());
        }
        $cmd_activate->setName('Activer programmation');
        $cmd_activate->setType('action');
        $cmd_activate->setSubType('other');
        $cmd_activate->setOrder(30);
        $cmd_activate->save();
        self::applyWidgetScale($cmd_activate);

        $cmd_deactivate = $eqLogic->getCmd(null, 'schedule_deactivate');
        if (!is_object($cmd_deactivate)) {
            $cmd_deactivate = new RoboProgCmd();
            $cmd_deactivate->setLogicalId('schedule_deactivate');
            $cmd_deactivate->setEqLogic_id($eqLogic->getId());
        }
        $cmd_deactivate->setName('Désactiver programmation');
        $cmd_deactivate->setType('action');
        $cmd_deactivate->setSubType('other');
        $cmd_deactivate->setOrder(30);
        $cmd_deactivate->setDisplay('forceReturnLineAfter', '1');
        $cmd_deactivate->save();
        self::applyWidgetScale($cmd_deactivate);

        // Prochaine tonte (texte court résumé, voir estimateNextMow())
        $cmd_next = $eqLogic->getCmd(null, 'schedule_next_mow');
        if (!is_object($cmd_next)) {
            $cmd_next = new RoboProgCmd();
            $cmd_next->setLogicalId('schedule_next_mow');
            $cmd_next->setEqLogic_id($eqLogic->getId());
        }
        $cmd_next->setName('Prochaine tonte');
        $cmd_next->setType('info');
        $cmd_next->setSubType('string');
        $cmd_next->setOrder(31);
        $cmd_next->setDisplay('forceReturnLineAfter', '1');
        $cmd_next->save();
        self::applyWidgetScale($cmd_next);
        $eqLogic->checkAndUpdateCmd('schedule_next_mow', self::estimateNextMowShort($eqLogic, $config));

        // Marge fin de journée
        $cmd_margin_info = $eqLogic->getCmd(null, 'schedule_margin');
        if (!is_object($cmd_margin_info)) {
            $cmd_margin_info = new RoboProgCmd();
            $cmd_margin_info->setLogicalId('schedule_margin');
            $cmd_margin_info->setEqLogic_id($eqLogic->getId());
        }
        $cmd_margin_info->setName('Marge fin de journée');
        $cmd_margin_info->setType('info');
        $cmd_margin_info->setSubType('numeric');
        $cmd_margin_info->setUnite('min');
        $cmd_margin_info->setOrder(32);
        $cmd_margin_info->setDisplay('forceReturnLineAfter', '1');
        $cmd_margin_info->save();
        self::applyWidgetScale($cmd_margin_info);

        $cmd_margin_action = $eqLogic->getCmd(null, 'schedule_margin_set');
        if (!is_object($cmd_margin_action)) {
            $cmd_margin_action = new RoboProgCmd();
            $cmd_margin_action->setLogicalId('schedule_margin_set');
            $cmd_margin_action->setEqLogic_id($eqLogic->getId());
        }
        $cmd_margin_action->setName('Régler marge fin de journée');
        $cmd_margin_action->setType('action');
        $cmd_margin_action->setSubType('slider');
        $cmd_margin_action->setOrder(32);
        $cmd_margin_action->setConfiguration('minValue', 0);
        $cmd_margin_action->setConfiguration('maxValue', 600);
        $cmd_margin_action->setConfiguration('updateCmdId', $cmd_margin_info->getId());
        $cmd_margin_action->setValue($cmd_margin_info->getId());
        $cmd_margin_action->setDisplay('forceReturnLineAfter', '1');
        $cmd_margin_action->save();
        self::applyWidgetScale($cmd_margin_action);
        $eqLogic->checkAndUpdateCmd('schedule_margin', $config['margin_minutes']);
        $cmd_margin_action->event($config['margin_minutes']);

        // Espacement tontes
        $cmd_spacing_info = $eqLogic->getCmd(null, 'schedule_spacing');
        if (!is_object($cmd_spacing_info)) {
            $cmd_spacing_info = new RoboProgCmd();
            $cmd_spacing_info->setLogicalId('schedule_spacing');
            $cmd_spacing_info->setEqLogic_id($eqLogic->getId());
        }
        $cmd_spacing_info->setName('Espacement tontes');
        $cmd_spacing_info->setType('info');
        $cmd_spacing_info->setSubType('numeric');
        $cmd_spacing_info->setUnite('j');
        $cmd_spacing_info->setOrder(33);
        $cmd_spacing_info->setDisplay('forceReturnLineAfter', '1');
        $cmd_spacing_info->save();
        self::applyWidgetScale($cmd_spacing_info);

        $cmd_spacing_action = $eqLogic->getCmd(null, 'schedule_spacing_set');
        if (!is_object($cmd_spacing_action)) {
            $cmd_spacing_action = new RoboProgCmd();
            $cmd_spacing_action->setLogicalId('schedule_spacing_set');
            $cmd_spacing_action->setEqLogic_id($eqLogic->getId());
        }
        $cmd_spacing_action->setName('Régler espacement tontes');
        $cmd_spacing_action->setType('action');
        $cmd_spacing_action->setSubType('slider');
        $cmd_spacing_action->setOrder(33);
        $cmd_spacing_action->setConfiguration('minValue', 1);
        $cmd_spacing_action->setConfiguration('maxValue', 30);
        $cmd_spacing_action->setConfiguration('updateCmdId', $cmd_spacing_info->getId());
        $cmd_spacing_action->setValue($cmd_spacing_info->getId());
        $cmd_spacing_action->setDisplay('forceReturnLineAfter', '1');
        $cmd_spacing_action->save();
        self::applyWidgetScale($cmd_spacing_action);
        $eqLogic->checkAndUpdateCmd('schedule_spacing', $config['spacing_days']);
        $cmd_spacing_action->event($config['spacing_days']);

        // Seuil d'humidité
        $cmd_humidity_info = $eqLogic->getCmd(null, 'schedule_humidity_threshold');
        if (!is_object($cmd_humidity_info)) {
            $cmd_humidity_info = new RoboProgCmd();
            $cmd_humidity_info->setLogicalId('schedule_humidity_threshold');
            $cmd_humidity_info->setEqLogic_id($eqLogic->getId());
        }
        $cmd_humidity_info->setName("Seuil d'humidité");
        $cmd_humidity_info->setType('info');
        $cmd_humidity_info->setSubType('numeric');
        $cmd_humidity_info->setUnite('%');
        $cmd_humidity_info->setOrder(34);
        $cmd_humidity_info->setDisplay('forceReturnLineAfter', '1');
        $cmd_humidity_info->save();
        self::applyWidgetScale($cmd_humidity_info);

        $cmd_humidity_action = $eqLogic->getCmd(null, 'schedule_humidity_threshold_set');
        if (!is_object($cmd_humidity_action)) {
            $cmd_humidity_action = new RoboProgCmd();
            $cmd_humidity_action->setLogicalId('schedule_humidity_threshold_set');
            $cmd_humidity_action->setEqLogic_id($eqLogic->getId());
        }
        $cmd_humidity_action->setName("Régler seuil d'humidité");
        $cmd_humidity_action->setType('action');
        $cmd_humidity_action->setSubType('slider');
        $cmd_humidity_action->setOrder(34);
        $cmd_humidity_action->setConfiguration('minValue', 0);
        $cmd_humidity_action->setConfiguration('maxValue', 100);
        $cmd_humidity_action->setConfiguration('updateCmdId', $cmd_humidity_info->getId());
        $cmd_humidity_action->setValue($cmd_humidity_info->getId());
        $cmd_humidity_action->setDisplay('forceReturnLineAfter', '1');
        $cmd_humidity_action->save();
        self::applyWidgetScale($cmd_humidity_action);
        $eqLogic->checkAndUpdateCmd('schedule_humidity_threshold', $config['humidity_threshold']);
        $cmd_humidity_action->event($config['humidity_threshold']);
    }

    // Estimation courte (widget) du type de la prochaine tonte : normale,
    // bordures, rattrapage bordures, ou rattrapage bordures + tonte.
    // Estimation DÉTAILLÉE (bandeau bleu en haut de l'onglet), avec date
    // et heure quand c'est calculable. Distincte de estimateNextMowShort()
    // (texte court utilisé pour le widget et le tableau des conditions).
    public static function estimateNextMow($eqLogic, $config) {
        $checked = self::checkConfig($config);
        if ($checked !== true) {
            return array('text' => null, 'error' => "Configuration invalide, impossible d'estimer.");
        }

        $state = self::getState($eqLogic);
        $now = time();
        $today = date('Y-m-d', $now);

        if ($state['cycle_phase'] == 'edge_in_progress') {
            return array('text' => "En attente du retour du robot (bordures en cours) pour relancer la tonte classique.", 'error' => null);
        }

        $start_min = self::resolveTimeMinutes($config['time_start_cmd_id']);
        $end_min = self::resolveTimeMinutes($config['time_end_cmd_id']);
        if ($start_min === null || $end_min === null) {
            return array('text' => null, 'error' => "Heures invalides, impossible d'estimer.");
        }
        $latest_start = $end_min - intval($config['margin_minutes']);
        $now_minutes = intval(date('H', $now)) * 60 + intval(date('i', $now));

        // 1) Déjà tondu aujourd'hui, ou espacement pas encore respecté :
        // prochaine tonte au jour suivant éligible.
        if ($state['last_mow_date'] == $today) {
            $next_date = date('Y-m-d', strtotime($state['last_mow_date'] . " +{$config['spacing_days']} days"));
            return array('text' => "Prochaine tonte prévue le " . date('d/m/Y', strtotime($next_date)) . " à partir de " . self::formatMinutes($start_min) . " (si conditions réunies).", 'error' => null);
        }
        if (!empty($state['last_mow_date'])) {
            $diff_days = (strtotime($today) - strtotime($state['last_mow_date'])) / 86400;
            if ($diff_days < intval($config['spacing_days'])) {
                $next_date = date('Y-m-d', strtotime($state['last_mow_date'] . " +{$config['spacing_days']} days"));
                return array('text' => "Prochaine tonte prévue le " . date('d/m/Y', strtotime($next_date)) . " à partir de " . self::formatMinutes($start_min) . " (si conditions réunies).", 'error' => null);
            }
        }

        // 2) Bordures dues aujourd'hui (rattrapage ou jour normal) :
        // priorité sur la tonte classique, pas de créneau horaire fixe.
        $edge_available = !empty($config['edge_cmd_id']);
        if ($edge_available && $config['edge_catchup_enabled'] == '1' && $state['edge_catchup_pending']) {
            return array('text' => "Rattrapage de bordures prévu dès que les conditions le permettront (avant " . self::formatMinutes($latest_start) . ").", 'error' => null);
        }
        $edge_due_today = false;
        if ($edge_available && $state['last_edge_date'] !== $today) {
            if ($config['edge_mode'] == 'weekday') {
                $edge_due_today = self::isWeekdayDue($config['edge_weekdays']);
            } else {
                if (empty($state['last_edge_date'])) {
                    $edge_due_today = true;
                } else {
                    $diff_edge = (strtotime($today) - strtotime($state['last_edge_date'])) / 86400;
                    $edge_due_today = ($diff_edge >= intval($config['edge_interval_days']));
                }
            }
        }
        if ($edge_due_today) {
            return array('text' => "Coupe des bordures prévue aujourd'hui dès que les conditions le permettront (avant " . self::formatMinutes($latest_start) . ").", 'error' => null);
        }

        // 3) Fenêtre horaire déjà fermée aujourd'hui ?
        $window_closed_today = ($now_minutes > $latest_start);

        // 4) Humidité
        $humidity_val = self::getCmdValue($config['humidity_cmd_id']);
        $threshold = floatval($config['humidity_threshold']);
        $duration_needed = intval($config['humidity_duration_minutes']) * 60;

        if (!is_numeric($humidity_val) || floatval($humidity_val) > $threshold) {
            return array('text' => "Indéterminé pour l'instant : l'humidité actuelle ($humidity_val%) doit d'abord repasser sous {$config['humidity_threshold']}%, puis y rester {$config['humidity_duration_minutes']} min, avant de pouvoir estimer une heure.", 'error' => null);
        }

        $humidity_ok_since = $now - (isset($state['humidity_low_since']) && $state['humidity_low_since'] !== null ? $state['humidity_low_since'] : $now);
        $remaining_seconds = $duration_needed - $humidity_ok_since;

        // 5) Température (optionnelle)
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val = self::getCmdValue($config['temperature_cmd_id']);
            $temp_min = floatval($config['temperature_min']);
            $temp_max = floatval($config['temperature_max']);
            if (!is_numeric($temp_val) || floatval($temp_val) < $temp_min) {
                return array('text' => "Indéterminé pour l'instant : la température actuelle (" . ($temp_val !== null ? $temp_val : '?') . "°C) est sous le seuil minimum de {$config['temperature_min']}°C.", 'error' => null);
            }
            if (floatval($temp_val) > $temp_max) {
                return array('text' => "Indéterminé pour l'instant : la température actuelle ({$temp_val}°C) dépasse le seuil maximum de {$config['temperature_max']}°C (canicule).", 'error' => null);
            }
        }

        // 6) Condition météo (code figé en dur, voir isGoodWeather())
        if (!self::isGoodWeather($config)) {
            $cond_label = self::getCmdValue($config['condition_cmd_id']);
            return array('text' => "Indéterminé pour l'instant : toutes les autres conditions sont réunies, mais la condition météo actuelle" . ($cond_label ? " ($cond_label)" : '') . " ne correspond pas aux critères acceptés.", 'error' => null);
        }

        // 7) Batterie (optionnelle)
        if (!empty($config['battery_cmd_id'])) {
            $battery_val = self::getCmdValue($config['battery_cmd_id']);
            $battery_min = floatval($config['battery_min_percent']);
            if (!is_numeric($battery_val) || floatval($battery_val) < $battery_min) {
                return array('text' => "Indéterminé pour l'instant : la batterie actuelle (" . ($battery_val !== null ? $battery_val : '?') . "%) est sous le seuil minimum de {$config['battery_min_percent']}%.", 'error' => null);
            }
        }

        if ($remaining_seconds <= 0 && !$window_closed_today && $now_minutes >= $start_min) {
            return array('text' => "Toutes les conditions semblent réunies — la tonte devrait démarrer au prochain passage (≤ 5 min), sous réserve d'un temps dégagé.", 'error' => null);
        }

        $eta = $now + max(0, $remaining_seconds);
        $eta_minutes = intval(date('H', $eta)) * 60 + intval(date('i', $eta));

        if ($window_closed_today || $eta_minutes > $latest_start) {
            $next_date = date('Y-m-d', strtotime('+1 day', $now));
            return array('text' => "Trop tard pour aujourd'hui (fenêtre fermée à " . self::formatMinutes($latest_start) . ") — en supposant l'humidité inchangée, prochaine tentative le " . date('d/m/Y', strtotime($next_date)) . " à partir de " . self::formatMinutes($start_min) . ".", 'error' => null);
        }

        return array('text' => "En supposant l'humidité inchangée, la tonte pourrait démarrer vers " . date('H:i', $eta) . " aujourd'hui (sous réserve d'un temps dégagé au moment venu).", 'error' => null);
    }

    public static function previewLatestStart($time_start_raw, $time_end_raw, $margin_minutes) {
        $start_min = self::resolveTimeMinutes($time_start_raw);
        $end_min = self::resolveTimeMinutes($time_end_raw);
        if ($start_min === null || $end_min === null) {
            return array('valid' => false, 'value' => null, 'error' => 'Heure de début/fin non résolue');
        }
        $latest_start = $end_min - intval($margin_minutes);
        if ($latest_start < $start_min) {
            return array('valid' => false, 'value' => self::formatMinutes($latest_start), 'error' => 'Marge trop grande : plus aucun créneau de démarrage possible');
        }
        return array('valid' => true, 'value' => self::formatMinutes($latest_start), 'error' => null);
    }

    private static function formatMinutes($minutes) {
        if ($minutes === null) {
            return '?';
        }
        return sprintf('%02d:%02d', intval($minutes / 60), $minutes % 60);
    }

    public static function estimateNextMowShort($eqLogic, $config) {
        if ($config['enabled'] != '1') {
            return 'Programmation désactivée';
        }
        $state = self::getState($eqLogic);
        $today = date('Y-m-d');

        if ($state['cycle_phase'] == 'edge_in_progress') {
            return 'En attente du retour (bordures en cours), puis tonte classique';
        }
        if ($state['last_mow_date'] === $today) {
            return 'Déjà fait aujourd\'hui';
        }

        $spacing_ok = true;
        if (!empty($state['last_mow_date'])) {
            $diff_days = (strtotime($today) - strtotime($state['last_mow_date'])) / 86400;
            $spacing_ok = ($diff_days >= intval($config['spacing_days']));
        }

        $edge_available = !empty($config['edge_cmd_id']);
        if ($edge_available && $config['edge_catchup_enabled'] == '1' && $state['edge_catchup_pending'] && $spacing_ok) {
            if ($config['edge_resume_enabled'] == '1') {
                return 'Rattrapage bordures, puis tonte classique';
            }
            return 'Rattrapage bordures';
        }

        $edge_due_today = false;
        if ($edge_available && $state['last_edge_date'] !== $today) {
            if ($config['edge_mode'] == 'weekday') {
                $edge_due_today = self::isWeekdayDue($config['edge_weekdays']);
            } else {
                if (empty($state['last_edge_date'])) {
                    $edge_due_today = true;
                } else {
                    $diff_edge = (strtotime($today) - strtotime($state['last_edge_date'])) / 86400;
                    $edge_due_today = ($diff_edge >= intval($config['edge_interval_days']));
                }
            }
        }
        if ($edge_due_today) {
            if ($config['edge_resume_enabled'] == '1') {
                return 'Bordures, puis tonte classique';
            }
            return 'Bordures';
        }

        if (!$spacing_ok && !empty($state['last_mow_date'])) {
            $diff_days = intval((strtotime($today) - strtotime($state['last_mow_date'])) / 86400);
            $remaining = max(0, intval($config['spacing_days']) - $diff_days);
            return 'Tonte classique dans ' . $remaining . ' jour(s)';
        }

        return 'Tonte classique (si conditions réunies)';
    }

    public static function handleWidgetAction($eqLogic, $logicalId, $value) {
        $config = self::getConfig($eqLogic);

        if ($logicalId == 'schedule_margin_set') {
            $config['margin_minutes'] = intval($value);
        } elseif ($logicalId == 'schedule_spacing_set') {
            $config['spacing_days'] = max(1, min(30, intval($value)));
        } elseif ($logicalId == 'schedule_humidity_threshold_set') {
            $config['humidity_threshold'] = max(0, min(100, intval($value)));
        } elseif ($logicalId == 'schedule_activate' || $logicalId == 'schedule_deactivate') {
            $want_enable = ($logicalId == 'schedule_activate');
            if ($want_enable) {
                $checked = self::checkConfig($config);
                if ($checked !== true) {
                    log::add('RoboProg', 'error', "Impossible d'activer la programmation (" . $config['robot_name'] . ') : ' . implode(' | ', $checked));
                    try {
                        message::add('RoboProg', "Impossible d'activer la programmation de \"" . $config['robot_name'] . '" : configuration incomplète (voir onglet Programmation pour le détail).');
                    } catch (\Throwable $e) {
                    }
                    self::syncWidgetCommands($eqLogic);
                    return;
                }
            }
            $config['enabled'] = $want_enable ? '1' : '0';
        }

        self::saveConfig($eqLogic, $config);
        self::syncWidgetCommands($eqLogic);
    }

    /* ================================================================ */
    /* Cron                                                              */
    /* ================================================================ */

    public static function cron5() {
        foreach (self::byType('RoboProg') as $eqLogic) {
            if ($eqLogic->getIsEnable() == 1) {
                try {
                    self::syncListener($eqLogic); // auto-réparation si la ligne s'est perdue
                    self::evaluate($eqLogic);
                } catch (\Throwable $e) {
                    log::add('RoboProg', 'error', 'evaluate() : ' . $e->getMessage());
                }
            }
        }
    }

    /* ================================================================ */
    /* Cycle de vie eqLogic                                              */
    /* ================================================================ */

    public function preInsert() {
    }

    public function preSave() {
    }

    public function postSave() {
        self::syncCmds($this);
        self::syncListener($this);
    }

    public function preRemove() {
        $listener = listener::byClassAndFunction('RoboProg', 'onCmdChange', array('eqLogic_id' => intval($this->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
    }

    // Réagit instantanément au changement de valeur de n'importe quelle
    // commande externe surveillée (pluie, humidité, température,
    // condition météo, batterie, statut), en plus du cron toutes les 5
    // minutes (qui reste un filet de sécurité). Rebâti à chaque
    // sauvegarde de config via syncListener().
    public static function onCmdChange($_option) {
        if (empty($_option['eqLogic_id'])) {
            return;
        }
        $eqLogic = eqLogic::byId($_option['eqLogic_id']);
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            return;
        }
        try {
            self::evaluate($eqLogic);
        } catch (\Throwable $e) {
            log::add('RoboProg', 'error', 'onCmdChange() : ' . $e->getMessage());
        }
    }

    // (Re)construit le listener de cet équipement à partir des commandes
    // actuellement configurées. Toujours reconstruit intégralement (pas
    // de mise à jour incrémentale) pour éviter tout risque de doublon ou
    // d'événement périmé après une modification de la config.
    public static function syncListener($eqLogic) {
        $config = self::getConfig($eqLogic);

        $listener = listener::byClassAndFunction('RoboProg', 'onCmdChange', array('eqLogic_id' => intval($eqLogic->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }

        $watched_keys = array('rain_cmd_id', 'rain_extra_cmd_id', 'humidity_cmd_id', 'temperature_cmd_id', 'condition_id_cmd_id', 'battery_cmd_id', 'status_cmd_id');
        $tags = array();
        foreach ($watched_keys as $key) {
            if (!empty($config[$key])) {
                $tags[] = $config[$key];
            }
        }
        if (empty($tags)) {
            return;
        }

        $listener = new listener();
        $listener->setClass('RoboProg');
        $listener->setFunction('onCmdChange');
        $listener->setOption(array('eqLogic_id' => intval($eqLogic->getId())));
        foreach ($tags as $tag) {
            $listener->addEvent($tag);
        }
        $listener->save();
    }

    public static function syncCmds($eqLogic) {
        $cmd = $eqLogic->getCmd(null, 'status_home_recorded');
        if (!is_object($cmd)) {
            $cmd = new RoboProgCmd();
            $cmd->setLogicalId('status_home_recorded');
            $cmd->setEqLogic_id($eqLogic->getId());
        }
        $cmd->setName('Statut "maison" enregistré');
        $cmd->setType('info');
        $cmd->setSubType('binary');
        $cmd->setIsVisible(1);
        $cmd->setOrder(2);
        $cmd->save();

        $cmd = $eqLogic->getCmd(null, 'record_home_status');
        if (!is_object($cmd)) {
            $cmd = new RoboProgCmd();
            $cmd->setLogicalId('record_home_status');
            $cmd->setEqLogic_id($eqLogic->getId());
        }
        $cmd->setName('Enregistrer le statut "à la maison"');
        $cmd->setType('action');
        $cmd->setSubType('other');
        $cmd->setIsVisible(1);
        $cmd->setOrder(3);
        $cmd->setDisplay('forceReturnLineAfter', '1');
        $cmd->save();

        $config = self::getConfig($eqLogic);
        $recorded = ($config['status_home_value'] !== null && $config['status_home_value'] !== '');
        $eqLogic->checkAndUpdateCmd('status_home_recorded', $recorded ? 1 : 0);

        self::syncWidgetCommands($eqLogic);
    }

    public function doAction($action, $value = null) {
        if ($action == 'record_home_status') {
            self::recordHomeStatus($this);
            self::syncCmds($this);
            return true;
        }
        if (in_array($action, array('schedule_activate', 'schedule_deactivate', 'schedule_margin_set', 'schedule_spacing_set', 'schedule_humidity_threshold_set'))) {
            self::handleWidgetAction($this, $action, $value);
            return true;
        }
        return false;
    }
}

class RoboProgCmd extends cmd {
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        $logicalId = $this->getLogicalId();
        $value = isset($_options['slider']) ? $_options['slider'] : (isset($_options['value']) ? $_options['value'] : null);
        $eqLogic->doAction($logicalId, $value);
    }
}
