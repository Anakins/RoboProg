<?php

/**
 * LandroidRTKScheduler — Programmation automatique de tonte.
 *
 * Fichier VOLONTAIREMENT séparé du reste du plugin (LandroidRTK.class.php)
 * pour ne jamais toucher au code existant qui fonctionne déjà.
 *
 * Toute la configuration de la programmation d'un équipement est stockée
 * dans une seule clé de configuration JSON : "mowing_schedule".
 */
class LandroidRTKScheduler {

    /**
     * Résout une chaîne saisie par l'utilisateur vers un objet cmd.
     * Accepte le format Jeedom natif "#[Objet][Équipement][Commande]#"
     * (identique à ce qu'on colle dans un scénario), OU directement un
     * id numérique de commande. Retourne null si non résolvable.
     */
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

    /**
     * Pour les champs d'heure uniquement : accepte soit un tag de
     * commande (comme resolveCmd), soit directement un horaire fixe
     * saisi au format HMM/HHMM (ex: 620, 1730). Retourne
     * array('mode' => 'cmd'|'fixed', 'cmd' => objet|null, 'minutes' => int|null).
     */
    public static function resolveTimeField($input) {
        if ($input === null || $input === '') {
            return array('mode' => null, 'cmd' => null, 'minutes' => null);
        }
        $input = trim($input);
        if (is_numeric($input) && strpos($input, '#') === false) {
            $minutes = self::parseHM($input);
            return array('mode' => 'fixed', 'cmd' => null, 'minutes' => $minutes);
        }
        $cmd = self::resolveCmd($input);
        return array('mode' => 'cmd', 'cmd' => $cmd, 'minutes' => null);
    }


    // Familles de codes météo "temps sec/dégagé" (mêmes plages que le
    // scénario fourni par l'utilisateur : 800-804 et 1000-1009).
    public static $GOOD_WEATHER_RANGES = array(
        array(800, 804),
        array(1000, 1009),
    );

    // Codes météo OpenWeatherMap connus (200-232 orage, 300-321 bruine,
    // 500-531 pluie, 600-622 neige, 701-781 atmosphère, 800 dégagé,
    // 801-804 nuages). Codé en dur : la page officielle OWM
    // (openweathermap.org/api/weather-conditions) charge son tableau en
    // JavaScript et n'est pas exploitable par une simple requête HTTP
    // côté serveur — cette liste est donc une référence stable connue,
    // pas une extraction en direct de leur site.
    public static $OWM_KNOWN_CODES = array(
        200,201,202,210,211,212,221,230,231,232,
        300,301,302,310,311,312,313,314,321,
        500,501,502,503,504,511,520,521,522,531,
        600,601,602,611,612,613,615,616,620,621,622,
        701,711,721,731,741,751,761,762,771,781,
        800,801,802,803,804,
    );

    // Libellés anglais standards OpenWeatherMap (stables depuis des années,
    // codés en dur car leur page n'est pas exploitable en direct).
    public static $OWM_CODE_LABELS = array(
        200 => 'thunderstorm with light rain', 201 => 'thunderstorm with rain', 202 => 'thunderstorm with heavy rain',
        210 => 'light thunderstorm', 211 => 'thunderstorm', 212 => 'heavy thunderstorm',
        221 => 'ragged thunderstorm', 230 => 'thunderstorm with light drizzle', 231 => 'thunderstorm with drizzle', 232 => 'thunderstorm with heavy drizzle',
        300 => 'light intensity drizzle', 301 => 'drizzle', 302 => 'heavy intensity drizzle',
        310 => 'light intensity drizzle rain', 311 => 'drizzle rain', 312 => 'heavy intensity drizzle rain',
        313 => 'shower rain and drizzle', 314 => 'heavy shower rain and drizzle', 321 => 'shower drizzle',
        500 => 'light rain', 501 => 'moderate rain', 502 => 'heavy intensity rain', 503 => 'very heavy rain',
        504 => 'extreme rain', 511 => 'freezing rain', 520 => 'light intensity shower rain',
        521 => 'shower rain', 522 => 'heavy intensity shower rain', 531 => 'ragged shower rain',
        600 => 'light snow', 601 => 'snow', 602 => 'heavy snow', 611 => 'sleet', 612 => 'light shower sleet',
        613 => 'shower sleet', 615 => 'light rain and snow', 616 => 'rain and snow',
        620 => 'light shower snow', 621 => 'shower snow', 622 => 'heavy shower snow',
        701 => 'mist', 711 => 'smoke', 721 => 'haze', 731 => 'sand/dust whirls', 741 => 'fog',
        751 => 'sand', 761 => 'dust', 762 => 'volcanic ash', 771 => 'squalls', 781 => 'tornado',
        800 => 'clear sky', 801 => 'few clouds', 802 => 'scattered clouds', 803 => 'broken clouds', 804 => 'overcast clouds',
    );

    /**
     * Retourne le libellé anglais connu d'un code météo (OWM en dur,
     * WeatherAPI récupéré en direct/mis en cache), ou null si inconnu.
     * Utilisé pour l'aperçu à côté du champ condition_id.
     */
    public static function getConditionCodeLabel($id) {
        if (!is_numeric($id)) {
            return null;
        }
        $id = intval($id);
        if (isset(self::$OWM_CODE_LABELS[$id])) {
            return self::$OWM_CODE_LABELS[$id] . ' (OpenWeatherMap)';
        }
        $labels = self::getWeatherApiCodeLabels();
        if (isset($labels[$id])) {
            return $labels[$id] . ' (WeatherAPI)';
        }
        return null;
    }

    /**
     * Récupère (avec cache local) la vraie liste de codes WeatherAPI,
     * directement depuis leur JSON officiel (accessible sans JS,
     * contrairement à celui d'OpenWeatherMap). Retourne
     * array('codes' => [...], 'source' => 'live'|'cache'|'hardcoded', 'error' => null|string)
     */
    public static function getWeatherApiCodes() {
        $cache_file = jeedom::getTmpFolder('LandroidRTK') . '/weatherapi_codes.json';
        $cache_max_age = 7 * 86400; // 1 semaine

        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_max_age) {
            $cached = json_decode(file_get_contents($cache_file), true);
            if (is_array($cached) && !empty($cached)) {
                return array('codes' => $cached, 'source' => 'cache', 'error' => null);
            }
        }

        $context = stream_context_create(array('http' => array('timeout' => 5)));
        $raw = @file_get_contents('https://www.weatherapi.com/docs/weather_conditions.json', false, $context);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                $codes = array_column($data, 'code');
                @file_put_contents($cache_file, json_encode($codes));
                return array('codes' => $codes, 'source' => 'live', 'error' => null);
            }
        }

        // Repli non bloquant : liste connue en dur (copie de secours, peut
        // être légèrement obsolète si WeatherAPI a changé ses codes).
        $fallback = array(1000,1003,1006,1009,1012,1015,1018,1021,1024,1027,1030,1033,1036,1039,1042,1045,1048,1063,1066,1069,1072,1087,1114,1117,1135,1147,1150,1153,1168,1171,1180,1183,1186,1189,1192,1195,1198,1201,1204,1207,1210,1213,1216,1219,1222,1225,1237,1240,1243,1246,1249,1252,1255,1258,1261,1264,1273,1276,1279,1282);
        return array('codes' => $fallback, 'source' => 'hardcoded', 'error' => "Impossible de joindre weatherapi.com pour vérifier les codes météo (page injoignable ou format changé) — utilisation d'une liste de secours, qui peut être légèrement obsolète.");
    }

    /**
     * Comme getWeatherApiCodes() mais renvoie un tableau [code => libellé
     * anglais (jour)]. Repli en dur limité aux 4 codes "beau temps" (les
     * seuls qui comptent vraiment pour ce plugin) si l'API est injoignable.
     */
    public static function getWeatherApiCodeLabels() {
        $cache_file = jeedom::getTmpFolder('LandroidRTK') . '/weatherapi_labels.json';
        $cache_max_age = 7 * 86400;

        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_max_age) {
            $cached = json_decode(file_get_contents($cache_file), true);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $context = stream_context_create(array('http' => array('timeout' => 5)));
        $raw = @file_get_contents('https://www.weatherapi.com/docs/weather_conditions.json', false, $context);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                $labels = array();
                foreach ($data as $entry) {
                    if (isset($entry['code']) && !empty($entry['day'])) {
                        $labels[intval($entry['code'])] = $entry['day'];
                    }
                }
                @file_put_contents($cache_file, json_encode($labels));
                return $labels;
            }
        }

        return array(1000 => 'Sunny', 1003 => 'Partly cloudy', 1006 => 'Cloudy', 1009 => 'Overcast');
    }

    public static function isKnownConditionCode($condition_id) {
        if (!is_numeric($condition_id)) {
            return array('known' => false, 'warning' => null);
        }
        $id = intval($condition_id);
        if (in_array($id, self::$OWM_KNOWN_CODES)) {
            return array('known' => true, 'warning' => null);
        }
        $weatherapi = self::getWeatherApiCodes();
        if (in_array($id, $weatherapi['codes'])) {
            return array('known' => true, 'warning' => $weatherapi['error']);
        }
        return array('known' => false, 'warning' => $weatherapi['error']);
    }

    /**
     * Vérifie une description météo textuelle par rapport aux libellés
     * connus de WeatherAPI (jour/nuit). La page OpenWeatherMap n'étant
     * pas exploitable en direct (JS), on ne peut fiabiliser que sur cette
     * source-là ; non bloquant dans tous les cas.
     */
    /**
     * Vérification simple et indépendante de la langue : la description
     * météo doit être un texte d'au moins 3 lettres, pas un nombre. On a
     * abandonné la comparaison stricte à la liste WeatherAPI (qui est en
     * anglais, alors que la plupart des plugins météo traduisent la
     * description en français) — filtre pas mal les erreurs de
     * paramétrage sans faux positifs liés à la langue.
     */
    public static function isKnownConditionText($text) {
        if (empty($text)) {
            return array('known' => false, 'warning' => null);
        }
        $text = trim($text);
        if (is_numeric($text)) {
            return array('known' => false, 'warning' => null);
        }
        $letters_only = preg_replace('/[^\p{L}]/u', '', $text);
        $known = (mb_strlen($letters_only) >= 3);
        return array('known' => $known, 'warning' => null);
    }

    // Emojis standards (indépendants de tout plugin météo), sélectionnés
    // à partir des familles de codes OpenWeatherMap ET WeatherAPI.com (les
    // deux sources supportées par le plugin météo générique). Un seul
    // emoji par grande famille (orage, bruine, pluie, neige/grésil) —
    // brume/poussière et tornade/vent violent ne sont volontairement pas
    // catégorisées (retombent sur l'inconnu).
    public static function getEmoji($condition_id) {
        if ($condition_id === null || $condition_id === '') {
            return '❔';
        }
        $id = intval($condition_id);
        // Codes "beau temps" OpenWeatherMap (les seuls autorisés à tondre) :
        // 800 dégagé, 801 quelques nuages, 802 nuages épars, 803 nuages
        // fragmentés, 804 couvert.
        if ($id == 800) return '☀️';
        if ($id == 801) return '🌤️';
        if ($id == 802) return '⛅';
        if ($id == 803) return '🌥️';
        if ($id == 804) return '☁️';
        // Codes "beau temps" WeatherAPI : 1000 ensoleillé, 1003 partiellement
        // nuageux, 1006 nuageux, 1009 couvert.
        if ($id == 1000) return '☀️';
        if ($id == 1003) return '🌤️';
        if ($id == 1006) return '⛅';
        if ($id == 1009) return '☁️';

        // --- Orage (thunderstorm) ---
        if ($id >= 200 && $id < 300) return '⛈️';
        if (in_array($id, array(1087, 1273, 1276, 1279, 1282))) return '⛈️';

        // --- Bruine (drizzle) ---
        if ($id >= 300 && $id < 400) return '🌦️';
        if (in_array($id, array(1063, 1072, 1150, 1153, 1168))) return '🌦️';

        // --- Pluie (rain) ---
        if ($id >= 500 && $id < 600) return '🌧️';
        if (in_array($id, array(1171, 1180, 1183, 1186, 1189, 1192, 1195, 1198, 1201, 1240, 1243, 1246))) return '🌧️';

        // --- Neige / grésil (snow / sleet) ---
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

    public static function isGoodWeather($condition_id) {
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

    /**
     * Parse un horaire au format "HMM"/"HHMM" (sans séparateur), tel que
     * renvoyé par le plugin météo officiel de Jeedom pour le lever/coucher
     * du soleil (ex: 820 = 08h20, 2006 = 20h06).
     * Retourne le nombre de minutes depuis minuit, ou null si invalide.
     */
    public static function parseHM($raw) {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $raw = intval($raw);
        if ($raw < 0 || $raw > 2359) {
            return null;
        }
        $minutes = $raw % 100;
        $hours = intval($raw / 100);
        if ($minutes < 0 || $minutes > 59 || $hours < 0 || $hours > 23) {
            return null;
        }
        return $hours * 60 + $minutes;
    }

    /* ---------------------------------------------------------------- */
    /* Lecture/écriture de la configuration                             */
    /* ---------------------------------------------------------------- */

    public static function getConfig($eqLogic) {
        $raw = $eqLogic->getConfiguration('mowing_schedule', '');
        $default = array(
            'enabled' => '0',
            'time_start_cmd_id' => '',
            'time_end_cmd_id' => '',
            'margin_minutes' => '0',
            'mow_duration_minutes' => '120',
            'spacing_days' => '1',
            // Pluie : 2 emplacements fixes, pas une liste.
            // 1) Le capteur pluie natif du robot (lecture seule, juste
            //    une case pour l'activer ou non).
            'rain_own_enabled' => '1',
            // 2) Un capteur externe optionnel (utilisé seulement s'il est
            //    rempli), avec opérateur de comparaison.
            'rain_extra_cmd_id' => '',
            'rain_extra_operator' => '==',
            'rain_extra_value' => '',
            'rain_interrupt_minutes' => '60',
            'humidity_cmd_id' => '',
            'humidity_threshold' => '65',
            'humidity_duration_minutes' => '180',
            // Optionnel : si vide, la température n'est pas prise en compte.
            'temperature_cmd_id' => '',
            'temperature_min' => '8',
            'temperature_max' => '40',
            'condition_id_cmd_id' => '',
            'condition_cmd_id' => '',
            'battery_min_percent' => '30',
            'notifications' => array(),
        );
        if ($raw == '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }
        return array_merge($default, $decoded);
    }

    public static function saveConfig($eqLogic, $config) {
        // Si le seuil d'humidité est ABAISSÉ (plus strict), on réinitialise
        // le suivi du délai (humidity_low_since) : le temps déjà écoulé a
        // été mesuré par rapport à l'ancien seuil, plus permissif, donc il
        // ne doit pas compter pour le nouveau. Si le seuil est RELEVÉ (plus
        // permissif), on ne touche à rien : le temps déjà passé sous un
        // seuil plus strict reste valable pour un seuil plus large.
        $old_config = self::getConfig($eqLogic);
        if (isset($old_config['humidity_threshold']) && isset($config['humidity_threshold'])
            && is_numeric($old_config['humidity_threshold']) && is_numeric($config['humidity_threshold'])
            && floatval($config['humidity_threshold']) < floatval($old_config['humidity_threshold'])) {
            $state = self::getState($eqLogic);
            if ($state['humidity_low_since'] !== null) {
                $state['humidity_low_since'] = null;
                self::saveState($eqLogic, $state);
                log::add('LandroidRTK', 'info', $eqLogic->getHumanName() . " : seuil d'humidité abaissé, délai de confirmation réinitialisé.");
            }
        }

        $eqLogic->setConfiguration('mowing_schedule', json_encode($config));
        $eqLogic->save();
        self::syncListener($eqLogic);
    }

    /* ---------------------------------------------------------------- */
    /* Validation (utilisée à l'affichage ET avant toute activation)     */
    /* ---------------------------------------------------------------- */

    /**
     * Vérification complète de la configuration. C'est LA fonction
     * utilisée à la fois par le bouton "Tester" (avec envoi réel d'un
     * message de test si $send_test_notif=true) et, en interne, avant
     * chaque évaluation automatique (sans envoi de test, pour ne pas
     * spammer les notifications toutes les 5 min).
     *
     * Retourne array('errors' => [...], 'warnings' => [...]).
     * - "errors" : bloque l'activation de la programmation.
     * - "warnings" : n'empêche PAS l'activation (ex: liste de codes
     *   météo injoignable), mais reste visible pour l'utilisateur.
     */
    public static function checkConfig($eqLogic, $config, $send_test_notif = false) {
        $errors = array();
        $warnings = array();

        foreach (array('time_start_cmd_id' => 'début', 'time_end_cmd_id' => 'fin') as $key => $label) {
            if (!isset($config[$key]) || $config[$key] === '') {
                $errors[] = "Aucune commande/heure de début $label renseignée.";
                continue;
            }
            $resolved = self::resolveTimeField($config[$key]);
            if ($resolved['mode'] === null) {
                $errors[] = "Le champ heure de $label est vide ou illisible.";
            } elseif ($resolved['mode'] == 'fixed') {
                if ($resolved['minutes'] === null) {
                    $errors[] = "L'heure de $label saisie directement n'est pas un horaire valide (format HMM/HHMM attendu, ex: 620 ou 1730).";
                }
            } else { // mode == 'cmd'
                if (!is_object($resolved['cmd'])) {
                    $errors[] = "La commande d'heure de $label est introuvable (tag invalide, ou équipement supprimé ?). Valeur saisie : \"" . $config[$key] . "\".";
                    self::notifyMissingEquipment($eqLogic, "heure de $label");
                } else {
                    $val = $resolved['cmd']->execCmd();
                    if (self::parseHM($val) === null) {
                        $errors[] = "La commande d'heure de $label ne renvoie pas un horaire valide (format HMM/HHMM attendu). Valeur actuelle : \"$val\".";
                    }
                }
            }
        }

        // Écart minimum entre début et fin (évite une fenêtre absurde ou
        // inversée, ex: début 20h / fin 8h).
        $start_check = self::resolveTimeField($config['time_start_cmd_id']);
        $end_check = self::resolveTimeField($config['time_end_cmd_id']);
        $start_min_check = ($start_check['mode'] == 'fixed') ? $start_check['minutes'] : (is_object($start_check['cmd']) ? self::parseHM($start_check['cmd']->execCmd()) : null);
        $end_min_check = ($end_check['mode'] == 'fixed') ? $end_check['minutes'] : (is_object($end_check['cmd']) ? self::parseHM($end_check['cmd']->execCmd()) : null);
        if ($start_min_check !== null && $end_min_check !== null) {
            $MIN_GAP_MINUTES = 60;
            if ($end_min_check <= $start_min_check) {
                $errors[] = "L'heure de fin (" . self::formatMinutes($end_min_check) . ") doit être postérieure à l'heure de début (" . self::formatMinutes($start_min_check) . ").";
            } elseif (($end_min_check - $start_min_check) < $MIN_GAP_MINUTES) {
                $errors[] = "L'écart entre l'heure de début et l'heure de fin doit être d'au moins $MIN_GAP_MINUTES minutes (actuellement " . ($end_min_check - $start_min_check) . " min).";
            }
        }

        if (!is_numeric($config['margin_minutes']) || $config['margin_minutes'] < 0) {
            $errors[] = "La marge avant l'heure de fin doit être un nombre positif ou nul.";
        }
        if (!is_numeric($config['mow_duration_minutes']) || $config['mow_duration_minutes'] < 30 || $config['mow_duration_minutes'] > 360) {
            $errors[] = "La durée de tonte estimée doit être comprise entre 30 et 360 minutes (6h).";
        }
        if (!is_numeric($config['spacing_days']) || $config['spacing_days'] < 1 || $config['spacing_days'] > 28) {
            $errors[] = "L'espacement entre 2 tontes doit être compris entre 1 et 28 jours.";
        }
        if (!is_numeric($config['rain_interrupt_minutes']) || $config['rain_interrupt_minutes'] < 20 || $config['rain_interrupt_minutes'] > 120) {
            $errors[] = "Le délai d'attente après une interruption pluie doit être compris entre 20 et 120 minutes (2h).";
        }

        if (!empty($config['rain_own_enabled']) && $config['rain_own_enabled'] == '1') {
            $cmd = $eqLogic->getCmd(null, 'rain_detected');
            if (!is_object($cmd)) {
                $errors[] = "Commande de détection pluie native du robot introuvable.";
            }
            $delay_cmd = $eqLogic->getCmd(null, 'rain_delay');
            if (is_object($delay_cmd)) {
                $delay_val = $delay_cmd->execCmd();
                if (is_numeric($delay_val) && floatval($delay_val) != 0) {
                    $warnings[] = "Le délai pluie du robot est actuellement de {$delay_val}h dans l'application Worx (pas 0h) — la détection pluie du robot risque de réagir en retard. Règle-le à 0h dans l'app Worx pour un comportement fiable.";
                } elseif (is_numeric($delay_val) && floatval($delay_val) == 0) {
                    $warnings[] = "Délai pluie du robot : 0h ✓ (réglage correct dans l'app Worx).";
                }
            }
        }

        if (!empty($config['rain_extra_cmd_id'])) {
            $cmd = self::resolveCmd($config['rain_extra_cmd_id']);
            if (!is_object($cmd)) {
                $errors[] = "La commande du capteur de pluie externe est introuvable (tag invalide, ou équipement supprimé ?). Valeur saisie : \"" . $config['rain_extra_cmd_id'] . "\".";
                self::notifyMissingEquipment($eqLogic, "capteur de pluie externe");
            } elseif ($config['rain_extra_value'] === '' || $config['rain_extra_value'] === null) {
                $errors[] = "Le capteur de pluie externe est renseigné mais sans valeur de déclenchement.";
            }
            if (!in_array($config['rain_extra_operator'], array('==', '!='))) {
                $errors[] = "Opérateur du capteur de pluie externe invalide (== ou != attendu).";
            }
        }

        if (empty($config['humidity_cmd_id'])) {
            $errors[] = "Aucune commande d'humidité renseignée (obligatoire).";
        } else {
            $cmd = self::resolveCmd($config['humidity_cmd_id']);
            if (!is_object($cmd)) {
                $errors[] = "La commande d'humidité est introuvable (tag invalide, ou équipement supprimé ?). Valeur saisie : \"" . $config['humidity_cmd_id'] . "\".";
                self::notifyMissingEquipment($eqLogic, "capteur d'humidité");
            } else {
                $val = $cmd->execCmd();
                if (!is_numeric($val) || $val < 0 || $val > 100) {
                    $errors[] = "La commande d'humidité doit renvoyer un nombre entre 0 et 100. Valeur actuelle : \"$val\".";
                }
            }
        }
        if (!is_numeric($config['humidity_threshold']) || $config['humidity_threshold'] < 0 || $config['humidity_threshold'] > 100) {
            $errors[] = "Le seuil d'humidité doit être compris entre 0 et 100.";
        }
        if (!is_numeric($config['humidity_duration_minutes']) || $config['humidity_duration_minutes'] < 0 || $config['humidity_duration_minutes'] > 300) {
            $errors[] = "Le délai d'humidité doit être compris entre 0 et 300 minutes.";
        }

        // Température : entièrement optionnelle. Si le champ reste vide,
        // ce critère est simplement ignoré à l'évaluation. Doit
        // obligatoirement pointer vers une commande Jeedom (comme
        // l'humidité).
        if (!empty($config['temperature_cmd_id'])) {
            $cmd = self::resolveCmd($config['temperature_cmd_id']);
            if (!is_object($cmd)) {
                $errors[] = "La commande de température est introuvable (tag invalide, ou équipement supprimé ?). Valeur saisie : \"" . $config['temperature_cmd_id'] . "\". Laissez ce champ vide si vous ne voulez pas tenir compte de la température.";
                self::notifyMissingEquipment($eqLogic, "capteur de température");
            } else {
                $val = $cmd->execCmd();
                if (!is_numeric($val)) {
                    $errors[] = "La commande de température doit renvoyer un nombre. Valeur actuelle : \"$val\".";
                }
            }
        }
        if (!is_numeric($config['temperature_min']) || $config['temperature_min'] < 4 || $config['temperature_min'] > 18) {
            $errors[] = "Le seuil minimum de température (protection gel) doit être compris entre 6 et 18°C.";
        }
        if (!is_numeric($config['temperature_max']) || $config['temperature_max'] < 30 || $config['temperature_max'] > 50) {
            $errors[] = "Le seuil maximum de température (protection canicule) doit être compris entre 30 et 50°C.";
        }

        if (empty($config['condition_id_cmd_id'])) {
            $errors[] = "Aucune commande de code météo (condition_id) renseignée (obligatoire).";
        } else {
            $cmd = self::resolveCmd($config['condition_id_cmd_id']);
            if (!is_object($cmd)) {
                $errors[] = "La commande de code météo est introuvable (tag invalide, ou équipement supprimé ?). Valeur saisie : \"" . $config['condition_id_cmd_id'] . "\".";
                self::notifyMissingEquipment($eqLogic, "code météo (condition_id)");
            } else {
                $val = $cmd->execCmd();
                if (!is_numeric($val)) {
                    $errors[] = "La commande de code météo (condition_id) ne renvoie pas un nombre. Valeur actuelle : \"$val\".";
                } else {
                    $check = self::isKnownConditionCode($val);
                    if ($check['warning']) {
                        $warnings[] = $check['warning'];
                    }
                    if (!$check['known']) {
                        $warnings[] = "Le code météo actuel ($val) ne correspond à aucun code connu d'OpenWeatherMap ou WeatherAPI — vérifiez votre source météo (n'empêche pas l'activation).";
                    }
                }
            }
        }
        if (empty($config['condition_cmd_id'])) {
            $errors[] = "Aucune commande de description météo (condition, texte) renseignée (obligatoire).";
        } else {
            $cmd = self::resolveCmd($config['condition_cmd_id']);
            if (!is_object($cmd)) {
                $errors[] = "La commande de description météo (condition) est introuvable (tag invalide, ou équipement supprimé ?). Valeur saisie : \"" . $config['condition_cmd_id'] . "\".";
                self::notifyMissingEquipment($eqLogic, "description météo (condition)");
            } else {
                $val = $cmd->execCmd();
                if ($val === '' || $val === null) {
                    $errors[] = "La commande de description météo (condition) ne renvoie aucune valeur.";
                } else {
                    $check = self::isKnownConditionText($val);
                    if ($check['warning']) {
                        $warnings[] = $check['warning'];
                    }
                    if (!$check['known']) {
                        $warnings[] = "La description météo actuelle (\"$val\") semble trop courte ou invalide (moins de 3 lettres) — vérifiez votre source météo (n'empêche pas l'activation).";
                    }
                }
            }
        }

        // La commande de batterie ("battery") est créée automatiquement
        // par le plugin principal sur chaque équipement synchronisé
        // depuis l'API Worx — aucun tag à saisir ici. On vérifie juste
        // qu'elle existe bien (cas limite : équipement pas encore
        // synchronisé une première fois) et qu'elle renvoie un nombre
        // cohérent.
        $battery_cmd = $eqLogic->getCmd(null, 'battery');
        if (!is_object($battery_cmd)) {
            $errors[] = "La commande de batterie (\"battery\") est introuvable sur cet équipement — attendez la prochaine synchronisation avec l'API Worx, ou vérifiez que l'équipement est bien à jour.";
            self::notifyMissingEquipment($eqLogic, "batterie du robot");
        } else {
            $val = $battery_cmd->execCmd();
            if (!is_numeric($val) || $val < 0 || $val > 100) {
                $errors[] = "La commande de batterie doit renvoyer un nombre entre 0 et 100. Valeur actuelle : \"$val\".";
            }
        }
        if (!is_numeric($config['battery_min_percent']) || $config['battery_min_percent'] < 20 || $config['battery_min_percent'] > 100) {
            $errors[] = "Le seuil minimum de batterie doit être compris entre 20 et 100.";
        }

        if (is_array($config['notifications'])) {
            foreach ($config['notifications'] as $i => $notif) {
                $n = $i + 1;
                if (empty($notif['cmd_id'])) {
                    $errors[] = "Notification #$n : aucune commande sélectionnée.";
                    continue;
                }
                $cmd = self::resolveCmd($notif['cmd_id']);
                if (!is_object($cmd)) {
                    $errors[] = "Notification #$n : commande introuvable (tag invalide, ou équipement supprimé ?). Valeur saisie : \"" . $notif['cmd_id'] . "\".";
                    self::notifyMissingEquipment($eqLogic, "notification #$n");
                    continue;
                }
                if ($send_test_notif) {
                    try {
                        $default_title = strtoupper($eqLogic->getName()) . ' - TONTE';
                        $title = '[TEST] ' . (!empty($notif['title']) ? $notif['title'] : $default_title);

                        $parts = array('[TEST]', "✂️ {$eqLogic->getName()} va tondre la pelouse.");
                        if (!empty($config['condition_cmd_id'])) {
                            $condition_cmd = self::resolveCmd($config['condition_cmd_id']);
                            if (is_object($condition_cmd)) {
                                $c = $condition_cmd->execCmd();
                                if ($c !== '' && $c !== null) {
                                    $weather_emoji = self::getEmoji(self::getCmdValue($config['condition_id_cmd_id']));
                                    $parts[] = "$weather_emoji $c";
                                }
                            }
                        }
                        if (!empty($config['temperature_cmd_id'])) {
                            $t = self::getTemperatureValue($config);
                            if ($t !== '' && $t !== null) {
                                $parts[] = "🌡️ La température est de {$t}°C.";
                            }
                        }
                        if (!empty($config['humidity_cmd_id'])) {
                            $humidity_cmd = self::resolveCmd($config['humidity_cmd_id']);
                            if (is_object($humidity_cmd)) {
                                $h = $humidity_cmd->execCmd();
                                if ($h !== '' && $h !== null) {
                                    $parts[] = "💧 L'humidité est de {$h}%.";
                                }
                            }
                        }
                        $b = self::getBatteryValue($eqLogic);
                        if ($b !== '' && $b !== null) {
                            $parts[] = "🔋 Batterie : {$b}%.";
                        }
                        $use_html = !empty($notif['html']) && $notif['html'] == '1';
                        $built = self::buildDualMessage($parts);
                        $message = $use_html ? $built['html'] : $built['plain'];

                        $cmd->execCmd(array('title' => $title, 'message' => $message));
                    } catch (\Throwable $e) {
                        $errors[] = "Notification #$n : échec de l'envoi de test (" . $e->getMessage() . ").";
                    }
                }
            }
        }

        return array('errors' => $errors, 'warnings' => $warnings);
    }

    /**
     * Avertit (log erreur + Centre de Messages Jeedom) qu'un équipement
     * requis par la programmation a disparu (ex: plugin météo tiers
     * désinstallé, capteur supprimé...). Limité à 1 notification par
     * équipement manquant et par jour, pour éviter le spam à chaque
     * passage du cron tant que le problème n'est pas corrigé.
     */
    private static function notifyMissingEquipment($eqLogic, $label) {
        $state = self::getState($eqLogic);
        $today = date('Y-m-d');
        if (!isset($state['missing_equipment_notified']) || !is_array($state['missing_equipment_notified'])) {
            $state['missing_equipment_notified'] = array();
        }
        if (isset($state['missing_equipment_notified'][$label]) && $state['missing_equipment_notified'][$label] == $today) {
            return; // déjà notifié aujourd'hui pour cet équipement précis
        }

        $msg = 'Programmation de tonte (' . $eqLogic->getHumanName() . ') : équipement manquant pour "' . $label . '" (supprimé de Jeedom, ou plugin tiers désinstallé — ex: plugin météo). La programmation ne peut pas démarrer tant que ce n\'est pas corrigé : va dans l\'onglet Programmation pour reconfigurer ce champ.';
        log::add('LandroidRTK', 'error', $msg);
        try {
            message::add('LandroidRTK', $msg);
        } catch (\Throwable $e) {
        }

        $state['missing_equipment_notified'][$label] = $today;
        self::saveState($eqLogic, $state);
    }

    /* ---------------------------------------------------------------- */
    /* Notifications                                                    */
    /* ---------------------------------------------------------------- */

    public static function sendNotifications($config, $title, $message_html, $message_plain, $type = 'default') {
        if (empty($config['notifications']) || !is_array($config['notifications'])) {
            return;
        }
        foreach ($config['notifications'] as $notif) {
            if (empty($notif['cmd_id'])) {
                continue;
            }
            // Filtre par type : les notifications "pas de tonte" ne partent
            // que vers les destinataires qui ont coché cette case (par
            // défaut activée, pour ne rien changer aux habitudes actuelles).
            if ($type == 'no_mow' && isset($notif['notify_no_mow']) && $notif['notify_no_mow'] == '0') {
                continue;
            }
            // Filtre par type : les notifications d'erreur robot ne partent
            // que vers les destinataires qui ont coché cette case (par
            // défaut désactivée : c'est une nouvelle option, on ne change
            // pas les habitudes existantes sans action explicite).
            if ($type == 'error' && (!isset($notif['notify_error']) || $notif['notify_error'] != '1')) {
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
                log::add('LandroidRTK', 'error', 'Scheduler: échec envoi notification cmd ' . $notif['cmd_id'] . ' : ' . $e->getMessage());
            }
        }
    }

    /* ---------------------------------------------------------------- */
    /* Notification d'erreur robot persistante                           */
    /* ---------------------------------------------------------------- */

    // Appelée à chaque refreshStatus() (toutes les 5 min) avec le libellé
    // d'erreur actuellement remonté par le robot ('' si aucune erreur).
    // N'envoie une notification que si la MÊME erreur persiste depuis au
    // moins 3 minutes (évite de notifier pour un blocage que le robot
    // résout tout seul en quelques secondes), et une seule fois par
    // occurrence (si l'erreur change ou disparaît puis revient, on
    // recompte 3 minutes avant de renotifier).
    public static function checkErrorNotification($eqLogic, $error_active, $error_label) {
        $config = self::getConfig($eqLogic);
        $state = self::getState($eqLogic);
        $error_label = trim((string) $error_label);

        if (!$error_active || $error_label === '') {
            // Plus d'erreur : on repart de zéro pour la prochaine.
            if ($state['error_pending_label'] !== null || $state['error_notified_label'] !== null) {
                $state['error_pending_label'] = null;
                $state['error_pending_since'] = null;
                $state['error_notified_label'] = null;
                self::saveState($eqLogic, $state);
            }
            return;
        }

        if ($state['error_pending_label'] !== $error_label) {
            // Nouvelle erreur (ou changement de libellé) : on démarre le
            // décompte de 3 minutes, sans notifier tout de suite.
            $state['error_pending_label'] = $error_label;
            $state['error_pending_since'] = time();
            self::saveState($eqLogic, $state);
            return;
        }

        if ($state['error_notified_label'] === $error_label) {
            return; // déjà notifié pour cette occurrence précise
        }

        if ($state['error_pending_since'] === null || (time() - intval($state['error_pending_since'])) < 180) {
            return; // pas encore 3 minutes que cette erreur persiste
        }

        $title = strtoupper($eqLogic->getName()) . ' - ERREUR';
        $text = "⚠️ {$eqLogic->getName()} est en erreur : {$error_label}";
        self::sendNotifications($config, $title, $text, $text, 'error');

        $state['error_notified_label'] = $error_label;
        self::saveState($eqLogic, $state);
    }

    /* ---------------------------------------------------------------- */
    /* État interne (suivi humidité / dernière tonte / anti-spam)        */
    /* ---------------------------------------------------------------- */

    private static function getState($eqLogic) {
        $raw = $eqLogic->getConfiguration('mowing_schedule_state', '');
        $default = array(
            'humidity_low_since' => null,
            'last_mow_date' => null,
            'last_notification_reason' => null,
            'last_notification_date' => null,
            // Horodatage du dernier démarrage de tonte déclenché par le
            // planificateur, tant qu'on est dans la fenêtre de "durée de
            // tonte estimée" (= la marge). Sert à détecter une pluie
            // pendant une tonte en cours (voir rain_interrupt_until).
            'current_mow_started_at' => null,
            // Si une pluie interrompt une tonte en cours : timestamp
            // avant lequel on ignore le critère d'humidité même s'il
            // redevient favorable (le temps que le sol absorbe la pluie).
            'rain_interrupt_until' => null,
            // Suivi de l'erreur robot en cours (voir checkErrorNotification) :
            // libellé actuellement observé, depuis quand, et le dernier
            // libellé pour lequel une notification a déjà été envoyée.
            'error_pending_label' => null,
            'error_pending_since' => null,
            'error_notified_label' => null,
        );
        if ($raw == '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }
        return array_merge($default, $decoded);
    }

    private static function saveState($eqLogic, $state) {
        $eqLogic->setConfiguration('mowing_schedule_state', json_encode($state));
        $eqLogic->save();
    }

    /* ---------------------------------------------------------------- */
    /* Outils manuels sur la "dernière tonte" (boutons dédiés)           */
    /* ---------------------------------------------------------------- */

    // [Débogage] Force la date de dernière tonte à hier, pour pouvoir
    // tester le déclenchement le jour même sans attendre l'espacement
    // complet configuré. On nettoie aussi les états liés à une tonte en
    // cours / un délai post-pluie pour ne pas fausser le test suivant.
    // Le suivi d'humidité (humidity_low_since) n'est volontairement PAS
    // touché : il reflète l'état réel du capteur.
    public static function debugSetLastMowYesterday($eqLogic) {
        $state = self::getState($eqLogic);
        $state['last_mow_date'] = date('Y-m-d', strtotime('-1 day'));
        $state['current_mow_started_at'] = null;
        $state['rain_interrupt_until'] = null;
        self::saveState($eqLogic, $state);
        log::add('LandroidRTK', 'info', 'Scheduler (' . $eqLogic->getHumanName() . '): [débogage] dernière tonte forcée à hier (' . $state['last_mow_date'] . ').');
        return $state;
    }

    // Marque la tonte du jour comme déjà faite (ex: tonte lancée
    // manuellement par l'utilisateur, hors programmation). Évite que le
    // planificateur ne déclenche une seconde tonte le même jour à cause
    // d'une date de dernière tonte trop ancienne en mémoire.
    public static function markLastMowToday($eqLogic) {
        $state = self::getState($eqLogic);
        $state['last_mow_date'] = date('Y-m-d');
        $state['current_mow_started_at'] = null;
        $state['rain_interrupt_until'] = null;
        self::saveState($eqLogic, $state);
        log::add('LandroidRTK', 'info', 'Scheduler (' . $eqLogic->getHumanName() . '): dernière tonte marquée comme faite aujourd\'hui (' . $state['last_mow_date'] . ') suite à une tonte manuelle.');
        return $state;
    }

    // [Débogage] Réinitialise l'anti-doublon quotidien des notifications
    // "pas de tonte" (une seule notification envoyée par jour et par
    // raison, voir notifyNotReady()), pour permettre de retester leur
    // envoi sans attendre le lendemain.
    public static function resetNotifThrottle($eqLogic) {
        $state = self::getState($eqLogic);
        $state['last_notification_reason'] = null;
        $state['last_notification_date'] = null;
        self::saveState($eqLogic, $state);
        log::add('LandroidRTK', 'info', 'Scheduler (' . $eqLogic->getHumanName() . '): [débogage] anti-doublon des notifications "pas de tonte" réinitialisé.');
    }

    /* ---------------------------------------------------------------- */
    /* Évaluation (appelée toutes les 5 min par le cron dédié)           */
    /* ---------------------------------------------------------------- */

    public static function cronScheduler() {
        foreach (LandroidRTK::byType('LandroidRTK', true) as $eqLogic) {
            self::syncListener($eqLogic); // auto-réparation si la ligne s'est perdue
            self::evaluate($eqLogic);
        }
    }

    // Réagit instantanément au changement de valeur de n'importe quelle
    // commande externe surveillée (pluie externe, humidité, température,
    // condition météo), en plus du cron toutes les 5 minutes (qui reste
    // un filet de sécurité). Rebâti à chaque sauvegarde de config via
    // syncListener().
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
            log::add('LandroidRTK', 'error', 'onCmdChange() : ' . $e->getMessage());
        }
    }

    // (Re)construit le listener de cet équipement à partir des commandes
    // actuellement configurées. Toujours reconstruit intégralement (pas
    // de mise à jour incrémentale) pour éviter tout risque de doublon ou
    // d'événement périmé après une modification de la config.
    public static function syncListener($eqLogic) {
        $config = self::getConfig($eqLogic);

        $listener = listener::byClassAndFunction('LandroidRTKScheduler', 'onCmdChange', array('eqLogic_id' => intval($eqLogic->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }

        $watched_keys = array('rain_extra_cmd_id', 'humidity_cmd_id', 'temperature_cmd_id', 'condition_id_cmd_id');
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
        $listener->setClass('LandroidRTKScheduler');
        $listener->setFunction('onCmdChange');
        $listener->setOption(array('eqLogic_id' => intval($eqLogic->getId())));
        foreach ($tags as $tag) {
            $listener->addEvent($tag);
        }
        $listener->save();
    }

    /**
     * Tableau de statut "condition par condition" affiché en haut de
     * l'onglet Programmation quand elle est active. Contrairement à
     * evaluate() (qui s'arrête à la première condition non remplie),
     * cette fonction évalue TOUJOURS toutes les conditions
     * indépendamment les unes des autres, pour donner une vue
     * d'ensemble complète de ce qui bloque (ou non) le démarrage.
     */
    public static function getConditionsStatus($eqLogic, $config) {
        $state = self::getState($eqLogic);
        $now = time();
        $today = date('Y-m-d', $now);
        $now_minutes = intval(date('H', $now)) * 60 + intval(date('i', $now));
        $rows = array();

        // --- Pas déjà tondu aujourd'hui ---
        $already_mowed = ($state['last_mow_date'] == $today);
        $rows[] = array(
            'label' => 'Pas déjà tondu aujourd\'hui',
            'ok' => !$already_mowed,
            'detail' => $already_mowed ? 'Dernière tonte : aujourd\'hui' : ($state['last_mow_date'] ? 'Dernière tonte : ' . $state['last_mow_date'] : 'Aucune tonte enregistrée'),
        );

        $mow_duration_seconds = max(60, intval($config['mow_duration_minutes']) * 60);
        $status_detail_early = null;
        if (!empty($state['current_mow_started_at']) && ($now - $state['current_mow_started_at']) <= $mow_duration_seconds) {
            $remaining_min = ceil(($mow_duration_seconds - ($now - $state['current_mow_started_at'])) / 60);
            $status_detail_early = "Tonte en cours (encore ~{$remaining_min} min avant fin estimée)";
        }

        // --- Espacement ---
        $spacing_ok = true;
        $spacing_detail = '—';
        if (!empty($state['last_mow_date']) && !$already_mowed) {
            $diff_days = (strtotime($today) - strtotime($state['last_mow_date'])) / 86400;
            $spacing_ok = ($diff_days >= intval($config['spacing_days']));
            $spacing_detail = round($diff_days) . ' jour(s) depuis la dernière tonte (min. ' . intval($config['spacing_days']) . ')';
        } elseif (empty($state['last_mow_date'])) {
            $spacing_detail = 'Aucune tonte enregistrée pour l\'instant';
        }
        $rows[] = array('label' => 'Espacement jours de tontes respecté', 'ok' => $spacing_ok, 'detail' => $spacing_detail);

        // --- Plage horaire ---
        $start_resolved = self::resolveTimeField($config['time_start_cmd_id']);
        $end_resolved = self::resolveTimeField($config['time_end_cmd_id']);
        $start_min = ($start_resolved['mode'] == 'fixed') ? $start_resolved['minutes'] : self::parseHM(is_object($start_resolved['cmd']) ? $start_resolved['cmd']->execCmd() : null);
        $end_min = ($end_resolved['mode'] == 'fixed') ? $end_resolved['minutes'] : self::parseHM(is_object($end_resolved['cmd']) ? $end_resolved['cmd']->execCmd() : null);
        $time_ok = false;
        $time_detail = 'Heure de début/fin non résolue';
        if ($start_min !== null && $end_min !== null) {
            $latest_start = $end_min - intval($config['margin_minutes']);
            $time_ok = ($now_minutes >= $start_min && $now_minutes <= $latest_start);
            $time_detail = 'Actuellement ' . sprintf('%02d:%02d', intdiv($now_minutes, 60), $now_minutes % 60);
        }
        $rows[] = array('label' => 'Dans la plage horaire autorisée', 'ok' => $time_ok, 'detail' => $time_detail);

        // --- Pluie (capteur robot et/ou externe) ---
        $rain_triggered = false;
        $rain_label = '';
        if (!empty($config['rain_own_enabled']) && $config['rain_own_enabled'] == '1') {
            $rain_cmd = $eqLogic->getCmd(null, 'rain_detected');
            if (is_object($rain_cmd)) {
                $val = trim((string) $rain_cmd->execCmd());
                if (strcasecmp($val, 'oui') == 0 || $val == '1') {
                    $rain_triggered = true;
                    $rain_label = 'capteur pluie du robot';
                }
            }
        }
        if (!$rain_triggered && !empty($config['rain_extra_cmd_id'])) {
            $cmd = self::resolveCmd($config['rain_extra_cmd_id']);
            if (is_object($cmd)) {
                $val = trim((string) $cmd->execCmd());
                $expected = trim((string) $config['rain_extra_value']);
                $is_numeric_cmp = is_numeric($val) && is_numeric($expected);
                $matches = $is_numeric_cmp ? (floatval($val) == floatval($expected)) : (strcasecmp($val, $expected) == 0);
                if ($config['rain_extra_operator'] == '!=') {
                    $matches = !$matches;
                }
                if ($matches) {
                    $rain_triggered = true;
                    $rain_label = $cmd->getName();
                }
            }
        }
        // --- Pluie / délai post-pluie / tonte en cours (ligne combinée) ---
        $status_detail = null;
        if ($rain_triggered) {
            $status_detail = "Pluie en cours ({$rain_label}) — pas de redémarrage tant qu'il pleut";
        } elseif (!empty($state['rain_interrupt_until']) && $now < $state['rain_interrupt_until']) {
            $remaining_min = ceil(($state['rain_interrupt_until'] - $now) / 60);
            $status_detail = "En attente du délai après pluie (encore {$remaining_min} min)";
        } else {
            $status_detail = $status_detail_early;
        }
        if ($status_detail !== null) {
            $rows[] = array('label' => 'Statut', 'ok' => true, 'detail' => $status_detail);
        }

        // --- Humidité ---
        $humidity_val = self::getCmdValue($config['humidity_cmd_id']);
        $threshold = floatval($config['humidity_threshold']);
        $humidity_below = is_numeric($humidity_val) && floatval($humidity_val) <= $threshold;
        $duration_needed = intval($config['humidity_duration_minutes']) * 60;
        $humidity_wait_ok = true;
        if ($humidity_below && !empty($state['humidity_low_since'])) {
            $humidity_wait_ok = ($now - $state['humidity_low_since']) >= $duration_needed;
        } elseif ($humidity_below && empty($state['humidity_low_since'])) {
            $humidity_wait_ok = ($duration_needed <= 0);
        }
        $humidity_ok = $humidity_below && $humidity_wait_ok;
        $humidity_detail = is_numeric($humidity_val) ? ($humidity_val . '% (seuil ' . $threshold . '%)') : 'Valeur indisponible';
        if ($humidity_below && !$humidity_wait_ok) {
            $elapsed = !empty($state['humidity_low_since']) ? ($now - $state['humidity_low_since']) : 0;
            $remaining_min = max(0, ceil(($duration_needed - $elapsed) / 60));
            $humidity_detail .= " — doit encore rester {$remaining_min} min sous ce seuil";
        }
        $rows[] = array('label' => 'Humidité sous le seuil (délai inclus)', 'ok' => $humidity_ok, 'detail' => $humidity_detail);

        // --- Température (optionnelle) ---
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val = self::getTemperatureValue($config);
            $temp_min = floatval($config['temperature_min']);
            $temp_max = floatval($config['temperature_max']);
            $temp_min_ok = is_numeric($temp_val) && floatval($temp_val) >= $temp_min;
            $temp_max_ok = is_numeric($temp_val) && floatval($temp_val) <= $temp_max;
            $temp_detail = is_numeric($temp_val) ? ($temp_val . '°C') : 'Valeur indisponible';
            $rows[] = array('label' => 'Température ≥ seuil minimum (' . $temp_min . '°C)', 'ok' => $temp_min_ok, 'detail' => $temp_detail);
            $rows[] = array('label' => 'Température ≤ seuil maximum (' . $temp_max . '°C)', 'ok' => $temp_max_ok, 'detail' => $temp_detail);
        }

        // --- Condition météo (optionnelle) ---
        if (!empty($config['condition_id_cmd_id']) || !empty($config['condition_cmd_id'])) {
            $condition_id = self::getCmdValue($config['condition_id_cmd_id']);
            $weather_ok = self::isGoodWeather($condition_id);
            $condition_label = self::getConditionLabel($config);
            $rows[] = array('label' => 'Condition météo actuelle acceptée', 'ok' => $weather_ok, 'detail' => $condition_label !== '' ? $condition_label : 'Valeur indisponible');
        }

        // --- Batterie ---
        $battery_val = self::getBatteryValue($eqLogic);
        $battery_min = floatval($config['battery_min_percent']);
        $battery_ok = is_numeric($battery_val) && floatval($battery_val) >= $battery_min;
        $rows[] = array(
            'label' => 'Batterie suffisante (min. ' . $battery_min . '%)',
            'ok' => $battery_ok,
            'detail' => is_numeric($battery_val) ? ($battery_val . '%') : 'Valeur indisponible',
        );

        $all_ok = true;
        foreach ($rows as $r) {
            if (!$r['ok']) {
                $all_ok = false;
                break;
            }
        }

        return array('rows' => $rows, 'all_ok' => $all_ok);
    }

    public static function evaluate($eqLogic) {
        $config = self::getConfig($eqLogic);
        self::syncWidgetCommands($eqLogic); // garde le widget à jour même si désactivé
        if (empty($config['enabled']) || $config['enabled'] != '1') {
            return;
        }

        $errors_warnings = self::checkConfig($eqLogic, $config, false);
        if (!empty($errors_warnings['errors'])) {
            log::add('LandroidRTK', 'error', 'Scheduler (' . $eqLogic->getHumanName() . '): configuration invalide, programmation ignorée : ' . implode(' | ', $errors_warnings['errors']));
            return;
        }

        $state = self::getState($eqLogic);
        $now = time();
        $today = date('Y-m-d', $now);
        $now_minutes = intval(date('H', $now)) * 60 + intval(date('i', $now));

        // Durée de tonte estimée (distincte de la marge avant l'heure de
        // fin) : sert à distinguer une vraie pluie pendant une tonte en
        // cours d'une fausse alerte plus tard le même jour, sans rapport
        // avec une tonte déjà terminée depuis longtemps.
        $mow_duration_seconds = max(60, intval($config['mow_duration_minutes']) * 60);
        $during_mow = !empty($state['current_mow_started_at']) && ($now - $state['current_mow_started_at']) <= $mow_duration_seconds;

        // --- Sécurité pluie : prioritaire sur tout le reste ---
        $rain_triggered = false;
        $rain_label = '';

        if (!empty($config['rain_own_enabled']) && $config['rain_own_enabled'] == '1') {
            $rain_cmd = $eqLogic->getCmd(null, 'rain_detected');
            if (is_object($rain_cmd)) {
                $val = trim((string) $rain_cmd->execCmd());
                if (strcasecmp($val, 'oui') == 0 || $val == '1') {
                    $rain_triggered = true;
                    $rain_label = 'capteur pluie du robot';
                }
            }
        }

        if (!$rain_triggered && !empty($config['rain_extra_cmd_id'])) {
            $cmd = self::resolveCmd($config['rain_extra_cmd_id']);
            if (is_object($cmd)) {
                $val = trim((string) $cmd->execCmd());
                $expected = trim((string) $config['rain_extra_value']);
                $is_numeric_cmp = is_numeric($val) && is_numeric($expected);
                $matches = $is_numeric_cmp
                    ? (floatval($val) == floatval($expected))
                    : (strcasecmp($val, $expected) == 0);
                if ($config['rain_extra_operator'] == '!=') {
                    $matches = !$matches;
                }
                if ($matches) {
                    $rain_triggered = true;
                    $rain_label = $cmd->getName();
                }
            }
        }

        if ($rain_triggered) {
            // Avant de renvoyer le robot à sa base et de notifier, on
            // vérifie son statut RÉEL (remonté par le daemon Worx), plus
            // fiable que nos propres variables de planification pour
            // savoir s'il est déjà à l'arrêt. Sans ça, une pluie détectée
            // un jour sans tonte (robot déjà à la station) déclenchait
            // quand même l'action et la notification "rentre à sa base",
            // ce qui n'a pas de sens.
            $status_cmd = $eqLogic->getCmd(null, 'status');
            $status_val = is_object($status_cmd) ? trim((string) $status_cmd->execCmd()) : '';
            $already_home = in_array($status_val, array('Dans la station', 'En charge'), true);

            if ($already_home) {
                return;
            }

            $interrupted = false;
            if ($during_mow) {
                // La pluie est arrivée pendant une tonte en cours (avant la
                // fin de la durée estimée) : on considère que la tonte n'a
                // pas eu lieu. On NE réinitialise PAS le compteur
                // d'humidité (humidity_low_since) — on impose plutôt un
                // délai fixe (réglable, "rain_interrupt_minutes") avant
                // tout redémarrage, que l'humidité redescende ou non entre
                // temps (le temps que le sol absorbe une grosse pluie).
                $state['last_mow_date'] = null;
                $state['rain_interrupt_until'] = $now + (intval($config['rain_interrupt_minutes']) * 60);
                $state['current_mow_started_at'] = null;
                $interrupted = true;
            }
            if ($state['last_notification_reason'] != 'rain' || $state['last_notification_date'] != $today) {
                $msg_html = "🌧️🏠 Il pleut ($rain_label). {$eqLogic->getName()} rentre à sa base.";
                if ($interrupted) {
                    $msg_html .= " La tonte en cours est considérée comme non effectuée ; un nouveau cycle reprendra une fois le sol ressuyé.";
                }
                $msg_plain = str_replace('<br/>', "\n", $msg_html);
                self::sendNotifications($config, strtoupper($eqLogic->getName()) . ' - PLUIE', $msg_html, $msg_plain);
                $state['last_notification_reason'] = 'rain';
                $state['last_notification_date'] = $today;
            }
            self::saveState($eqLogic, $state);
            $eqLogic->doAction('home');
            return;
        }

        // Pas de pluie actuellement : si une tonte déclenchée par le
        // planificateur a dépassé sa durée estimée sans interruption, on
        // considère qu'elle s'est terminée normalement.
        if (!empty($state['current_mow_started_at']) && !$during_mow) {
            $state['current_mow_started_at'] = null;
            self::saveState($eqLogic, $state);
        }

        // --- Suivi du délai d'humidité (humidity_low_since) ---
        // IMPORTANT : ce suivi doit tourner à CHAQUE cycle, quel que soit
        // l'espacement ou la plage horaire du jour — sinon, si l'humidité
        // repasse au-dessus du seuil un jour où on ne regarde pas (hors
        // plage horaire, ou avant que l'espacement ne soit atteint), le
        // compteur ne serait jamais remis à zéro et resterait "périmé",
        // ce qui ferait croire à tort que le délai est déjà écoulé dès que
        // l'humidité repasse sous le seuil bien plus tard.
        $humidity_val_tracking = self::getCmdValue($config['humidity_cmd_id']);
        $threshold_tracking = floatval($config['humidity_threshold']);
        if (!is_numeric($humidity_val_tracking) || floatval($humidity_val_tracking) > $threshold_tracking) {
            if ($state['humidity_low_since'] !== null) {
                $state['humidity_low_since'] = null;
                self::saveState($eqLogic, $state);
            }
        } elseif ($state['humidity_low_since'] === null) {
            $state['humidity_low_since'] = $now;
            self::saveState($eqLogic, $state);
        }

        // --- Espacement entre 2 tontes ---
        if ($state['last_mow_date'] == $today) {
            return; // déjà tondu aujourd'hui, rien à faire de plus
        }
        if (!empty($state['last_mow_date'])) {
            $diff_days = (strtotime($today) - strtotime($state['last_mow_date'])) / 86400;
            if ($diff_days < intval($config['spacing_days'])) {
                self::notifyNotReady($eqLogic, $config, $state, $today, 'spacing');
                return; // pas encore le jour
            }
        }

        // --- Plage horaire + marge (tag de commande OU heure fixe) ---
        $start_resolved = self::resolveTimeField($config['time_start_cmd_id']);
        $end_resolved = self::resolveTimeField($config['time_end_cmd_id']);
        $start_min = ($start_resolved['mode'] == 'fixed') ? $start_resolved['minutes'] : self::parseHM(is_object($start_resolved['cmd']) ? $start_resolved['cmd']->execCmd() : null);
        $end_min = ($end_resolved['mode'] == 'fixed') ? $end_resolved['minutes'] : self::parseHM(is_object($end_resolved['cmd']) ? $end_resolved['cmd']->execCmd() : null);
        if ($start_min === null || $end_min === null) {
            return; // déjà signalé par checkConfig(), on ne va pas plus loin
        }
        $latest_start = $end_min - intval($config['margin_minutes']);
        if ($now_minutes < $start_min) {
            return; // trop tôt, fenêtre pas encore ouverte aujourd'hui
        }
        // IMPORTANT : si la fenêtre est déjà fermée ($now_minutes >
        // $latest_start), on NE sort PAS ici — on continue volontairement
        // l'évaluation ci-dessous (humidité, météo, température, batterie)
        // afin de déterminer la VRAIE raison du blocage et de la notifier
        // via notifyNotReady() (qui gère elle-même le fait d'attendre la
        // fermeture de la fenêtre avant d'envoyer). Sortir ici en silence
        // empêcherait à tort TOUTE notification "pas de tonte" autre que
        // la raison "espacement" de partir un jour donné.
        $window_closed = ($now_minutes > $latest_start);

        // --- Humidité (doit être sous le seuil depuis assez longtemps) ---
        // Le suivi (set/reset de humidity_low_since) est déjà fait plus
        // haut, à chaque cycle. Ici on ne fait que lire l'état pour décider.
        $humidity_val = self::getCmdValue($config['humidity_cmd_id']);
        $threshold = floatval($config['humidity_threshold']);
        $duration_needed = intval($config['humidity_duration_minutes']) * 60;

        if (!is_numeric($humidity_val) || floatval($humidity_val) > $threshold) {
            self::notifyNotReady($eqLogic, $config, $state, $today, 'humidity');
            return;
        }
        $humidity_ok_since = $now - $state['humidity_low_since'];
        if ($humidity_ok_since < $duration_needed) {
            self::notifyNotReady($eqLogic, $config, $state, $today, 'humidity_wait');
            return;
        }

        // Délai fixe (réglable, "rain_interrupt_minutes") après une pluie
        // ayant interrompu une tonte en cours. Le contrôle d'humidité
        // ci-dessus s'exécute d'abord à chaque cycle : si l'humidité
        // remonte au-dessus du seuil entre temps, elle réinitialise déjà
        // le suivi normalement (ci-dessus). Ce délai s'ajoute ensuite,
        // même si l'humidité redevient favorable, le temps que le sol
        // absorbe une grosse pluie.
        $restarting_after_rain_interrupt = !empty($state['rain_interrupt_until']);
        if (!empty($state['rain_interrupt_until'])) {
            if ($now < $state['rain_interrupt_until']) {
                self::notifyNotReady($eqLogic, $config, $state, $today, 'rain_interrupt');
                return;
            }
            $state['rain_interrupt_until'] = null;
            self::saveState($eqLogic, $state);
        }

        // --- Température (optionnelle : ignorée si aucune commande sélectionnée) ---
        // Deux garde-fous : ne pas tondre s'il fait trop froid (risque
        // d'abîmer une pelouse potentiellement gelée) NI s'il fait trop
        // chaud (canicule, pour préserver le robot et la pelouse).
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val = self::getTemperatureValue($config);
            $temp_min = floatval($config['temperature_min']);
            $temp_max = floatval($config['temperature_max']);
            if (!is_numeric($temp_val) || floatval($temp_val) < $temp_min) {
                self::notifyNotReady($eqLogic, $config, $state, $today, 'temperature_low');
                return;
            }
            if (floatval($temp_val) > $temp_max) {
                self::notifyNotReady($eqLogic, $config, $state, $today, 'temperature_high');
                return;
            }
        }

        // --- Météo (code condition) ---
        $condition_id = self::getCmdValue($config['condition_id_cmd_id']);
        if (!self::isGoodWeather($condition_id)) {
            self::notifyNotReady($eqLogic, $config, $state, $today, 'weather');
            return;
        }

        // --- Batterie du robot (dernier contrôle avant tout lancement,
        // qu'il s'agisse d'un démarrage normal ou d'une reprise après
        // pluie) : si elle est trop faible, Worx refusera de toute façon
        // de démarrer en interne, mais nous n'en serions pas informés —
        // on penserait alors, à tort, que la tonte a bien eu lieu. On
        // reporte donc le déclenchement jusqu'à ce que la batterie
        // atteigne le seuil, puis on revérifie TOUTES les conditions
        // depuis le début au prochain cycle (aucun état à mémoriser ici :
        // il suffit de "return" sans rien déclencher).
        $battery_val = self::getBatteryValue($eqLogic);
        $battery_min = floatval($config['battery_min_percent']);
        if (!is_numeric($battery_val) || floatval($battery_val) < $battery_min) {
            self::notifyNotReady($eqLogic, $config, $state, $today, 'battery');
            return;
        }

        // --- Toutes les conditions "météo/robot" sont réunies, mais la
        // fenêtre horaire du jour est déjà fermée (cas rare : ex. Jeedom
        // relancé en cours de journée juste avant la fermeture) : on ne
        // lance PAS une tonte hors-plage, on notifie et on réessaiera au
        // prochain cycle (donc demain, une fois l'espacement respecté).
        if ($window_closed) {
            self::notifyNotReady($eqLogic, $config, $state, $today, 'window_closed');
            return;
        }

        // --- Toutes les conditions sont réunies : on lance la tonte ---
        // Ordre volontairement identique partout (notifications ET logs) :
        // annonce → météo (condition) → température (si réglée) →
        // humidité → batterie.
        $emoji = self::getEmoji($condition_id);
        $condition_label = self::getConditionLabel($config);
        $first_line = "✂️ {$eqLogic->getName()} va tondre la pelouse.";
        if ($restarting_after_rain_interrupt) {
            $first_line .= " (reprise après un arrêt pluie 🏠➡️✂️)";
        }
        $msg_parts = array(
            $first_line,
            "$emoji $condition_label",
        );
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val = self::getTemperatureValue($config);
            if ($temp_val !== null && $temp_val !== '') {
                $msg_parts[] = "🌡️ La température est de {$temp_val}°C.";
            }
        }
        $humidity_since_min = !empty($state['humidity_low_since']) ? round((time() - $state['humidity_low_since']) / 60) : null;
        $msg_parts[] = "💧 L'humidité est de {$humidity_val}%" . ($humidity_since_min !== null ? " (depuis {$humidity_since_min} min)" : '') . ".";
        $msg_parts[] = "🔋 Batterie : {$battery_val}%.";
        $msg = self::buildDualMessage($msg_parts);
        $msg_html = $msg['html'];
        $msg_plain = $msg['plain'];
        self::sendNotifications($config, strtoupper($eqLogic->getName()) . ' - TONTE', $msg_html, $msg_plain);

        $eqLogic->doAction('start');

        if ($restarting_after_rain_interrupt) {
            log::add('LandroidRTK', 'info', 'Scheduler (' . $eqLogic->getHumanName() . '): reprise de la tonte après une interruption pluie (délai de ' . intval($config['rain_interrupt_minutes']) . ' min écoulé, sol supposé ressuyé).');
        }

        $state['last_mow_date'] = $today;
        $state['current_mow_started_at'] = $now;
        $state['last_notification_reason'] = null;
        self::saveState($eqLogic, $state);

        $detail_log = 'Scheduler: tonte automatique lancée pour ' . $eqLogic->getHumanName() .
            ' | heure actuelle: ' . date('H:i', $now) .
            ' | plage autorisée: ' . self::formatMinutes($start_min) . '-' . self::formatMinutes($latest_start) .
            ' (marge ' . $config['margin_minutes'] . ' min avant fin ' . self::formatMinutes($end_min) . ')' .
            ' | condition météo: ' . $condition_id . ' (' . $condition_label . ')' .
            ' | température: ' . (!empty($config['temperature_cmd_id']) ? self::getTemperatureValue($config) . '°C' : 'non configurée') .
            ' | humidité: ' . $humidity_val . '% (seuil ' . $config['humidity_threshold'] . '%, sous le seuil depuis ' . round($humidity_ok_since / 60) . ' min, requis ' . $config['humidity_duration_minutes'] . ' min)' .
            ' | batterie: ' . $battery_val . '% (seuil ' . $config['battery_min_percent'] . '%)' .
            ' | dernière tonte: ' . (!empty($state['last_mow_date']) ? $state['last_mow_date'] : 'jamais') .
            ' (espacement requis: ' . $config['spacing_days'] . ' jour(s))';

        // Loggé à la fois en 'info' (résumé visible par défaut + Centre
        // de Messages, via logImportant) et en 'debug' (même contenu,
        // pour les installations qui filtrent leurs logs sur ce niveau).
        LandroidRTK::logImportant($detail_log);
        log::add('LandroidRTK', 'debug', $detail_log);
    }

    /**
     * Construit les 2 variantes d'un message à partir d'un tableau de
     * lignes : la version "brute" (avec de vrais retours à la ligne \n,
     * compris nativement par Discord et la plupart des messageries) et
     * la version HTML (mêmes lignes, jointes par <br/> — nécessaire pour
     * les destinataires comme Jeedom Connect qui rendent le message en
     * HTML et ignorent les \n bruts).
     */
    // Supprime UNIQUEMENT le tout dernier caractère d'une ligne si c'est
    // un espace (pas un trim complet : on ne touche pas aux espaces en
    // début de ligne ni aux espaces internes, qui peuvent servir à aligner
    // du texte en colonnes). Corrige un bug observé où un espace en tout
    // dernier caractère d'un message envoyé à Discord (via JeedomBot)
    // empêche le retour à la ligne suivant de fonctionner.
    private static function stripTrailingSpace($line) {
        if (substr($line, -1) === ' ') {
            return substr($line, 0, -1);
        }
        return $line;
    }

    private static function buildDualMessage($parts) {
        $parts = array_map(array(__CLASS__, 'stripTrailingSpace'), $parts);
        $plain = implode("\n", $parts);
        $html = implode('<br/>', $parts);
        return array('html' => $html, 'plain' => $plain);
    }

    private static function formatMinutes($minutes) {
        if ($minutes === null) {
            return '?';
        }
        return sprintf('%02d:%02d', intval($minutes / 60), $minutes % 60);
    }

    /**
     * Calcule l'heure limite de démarrage (heure de fin − marge) à partir
     * de champs "heure de début"/"heure de fin"/"marge" bruts (avant
     * sauvegarde) — utilisé pour l'aperçu en direct à côté du champ
     * "Marge avant l'heure de fin" dans le formulaire.
     */
    public static function previewLatestStart($time_start_raw, $time_end_raw, $margin_minutes) {
        $start_resolved = self::resolveTimeField($time_start_raw);
        $end_resolved = self::resolveTimeField($time_end_raw);
        $start_min = ($start_resolved['mode'] == 'fixed') ? $start_resolved['minutes'] : self::parseHM(is_object($start_resolved['cmd']) ? $start_resolved['cmd']->execCmd() : null);
        $end_min = ($end_resolved['mode'] == 'fixed') ? $end_resolved['minutes'] : self::parseHM(is_object($end_resolved['cmd']) ? $end_resolved['cmd']->execCmd() : null);
        if ($start_min === null || $end_min === null) {
            return array('valid' => false, 'value' => null, 'error' => 'Heure de début/fin non résolue');
        }
        $latest_start = $end_min - intval($margin_minutes);
        if ($latest_start < $start_min) {
            return array('valid' => false, 'value' => self::formatMinutes($latest_start), 'error' => 'Marge trop grande : plus aucun créneau de démarrage possible');
        }
        return array('valid' => true, 'value' => self::formatMinutes($latest_start), 'error' => null);
    }

    private static function notifyNotReady($eqLogic, $config, $state, $today, $reason) {
        // On attend la fermeture de la fenêtre de tonte du jour avant de
        // notifier (plutôt que dès la première vérification, potentiellement
        // en pleine nuit) — sauf si la fenêtre n'est pas calculable, auquel
        // cas on notifie normalement dès que le problème est détecté.
        $start_resolved = self::resolveTimeField($config['time_start_cmd_id']);
        $end_resolved = self::resolveTimeField($config['time_end_cmd_id']);
        $start_min = ($start_resolved['mode'] == 'fixed') ? $start_resolved['minutes'] : self::parseHM(is_object($start_resolved['cmd']) ? $start_resolved['cmd']->execCmd() : null);
        $end_min = ($end_resolved['mode'] == 'fixed') ? $end_resolved['minutes'] : self::parseHM(is_object($end_resolved['cmd']) ? $end_resolved['cmd']->execCmd() : null);
        if ($start_min !== null && $end_min !== null) {
            $latest_start = $end_min - intval($config['margin_minutes']);
            $now_minutes = intval(date('H')) * 60 + intval(date('i'));
            if ($now_minutes <= $latest_start) {
                return; // fenêtre encore ouverte aujourd'hui, on attend sa fermeture
            }
        }

        if ($state['last_notification_reason'] == $reason && $state['last_notification_date'] == $today) {
            return; // déjà notifié aujourd'hui pour cette même raison
        }
        $humidity_val = self::getCmdValue($config['humidity_cmd_id']);
        $condition_label = self::getConditionLabel($config);
        $condition_id = !empty($config['condition_id_cmd_id']) ? self::getCmdValue($config['condition_id_cmd_id']) : null;

        if ($reason == 'spacing') {
            $last_mow_str = !empty($state['last_mow_date']) ? date('d/m/Y', strtotime($state['last_mow_date'])) : 'inconnue';
            $msg = "{$eqLogic->getName()} ne tondra pas aujourd'hui car il est programmé pour tondre tous les {$config['spacing_days']} jours. La dernière tonte était le $last_mow_str.";
        } elseif ($reason == 'temperature_low') {
            $msg = "{$eqLogic->getName()} ne tondra pas aujourd'hui car la température est en dessous du seuil minimum de {$config['temperature_min']}°C (risque d'abîmer une pelouse potentiellement gelée).";
        } elseif ($reason == 'temperature_high') {
            $msg = "{$eqLogic->getName()} ne tondra pas aujourd'hui car la température dépasse le seuil maximum de {$config['temperature_max']}°C (canicule).";
        } elseif ($reason == 'battery') {
            $battery_val_msg = self::getBatteryValue($eqLogic);
            $msg = "{$eqLogic->getName()} attend que sa batterie remonte à au moins {$config['battery_min_percent']}% avant de démarrer (actuellement {$battery_val_msg}%). Toutes les conditions seront revérifiées une fois ce seuil atteint.";
        } elseif ($reason == 'rain_interrupt') {
            $wait_min = !empty($state['rain_interrupt_until']) ? ceil(($state['rain_interrupt_until'] - time()) / 60) : 0;
            $msg = "{$eqLogic->getName()} attend encore environ {$wait_min} min avant de reprendre le cycle normal, le temps que le sol absorbe la pluie récente.";
        } elseif ($reason == 'window_closed') {
            $msg = "{$eqLogic->getName()} ne tondra pas aujourd'hui : toutes les autres conditions étaient réunies, mais la fenêtre horaire autorisée s'est refermée avant que le démarrage ait pu être lancé.";
        } elseif ($reason == 'weather') {
            // Toutes les autres conditions (humidité, température, batterie,
            // espacement) sont réunies : seule la condition météo actuelle
            // ne correspond pas aux critères choisis pour tondre.
            $msg = "{$eqLogic->getName()} ne tondra pas pour l'instant : toutes les conditions sont réunies, sauf la condition météo actuelle" . ($condition_label !== '' ? " ($condition_label)" : '') . ", qui ne correspond pas aux critères choisis pour tondre.";
        } else {
            $msg = "{$eqLogic->getName()} ne tondra pas aujourd'hui car le temps était trop humide aujourd'hui.";
        }

        // Ordre volontairement identique partout (notifications ET logs) :
        // annonce → météo (condition) → température (si réglée) →
        // humidité → batterie.
        $lines = array("💤 $msg");
        if ($condition_label !== null && $condition_label !== '') {
            $condition_emoji = self::getEmoji($condition_id);
            $lines[] = "$condition_emoji Condition météo actuelle : $condition_label";
        }
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val_line = self::getTemperatureValue($config);
            if ($temp_val_line !== null && $temp_val_line !== '') {
                $lines[] = "🌡️ La température est actuellement de {$temp_val_line}°C";
            }
        }
        if ($humidity_val !== null && $humidity_val !== '') {
            $lines[] = "💧 L'humidité actuelle est de {$humidity_val}%";
        }
        if ($reason != 'battery') {
            $battery_val_line = self::getBatteryValue($eqLogic);
            if ($battery_val_line !== null && $battery_val_line !== '') {
                $lines[] = "🔋 La batterie est actuellement de {$battery_val_line}%";
            }
        }
        $built = self::buildDualMessage($lines);

        self::sendNotifications($config, strtoupper($eqLogic->getName()) . ' - PAS DE TONTE', $built['html'], $built['plain'], 'no_mow');

        // Même contenu loggé en 'info' et en 'debug' (voir remarque
        // équivalente dans evaluate() pour le lancement de la tonte).
        log::add('LandroidRTK', 'info', 'Scheduler (' . $eqLogic->getHumanName() . '): ' . $built['plain']);
        log::add('LandroidRTK', 'debug', 'Scheduler (' . $eqLogic->getHumanName() . ', raison=' . $reason . '): ' . $built['plain']);

        $state['last_notification_reason'] = $reason;
        $state['last_notification_date'] = $today;
        self::saveState($eqLogic, $state);
    }

    private static function getCmdValue($cmd_id) {
        if (empty($cmd_id)) {
            return null;
        }
        $cmd = self::resolveCmd($cmd_id);
        if (!is_object($cmd)) {
            return null;
        }
        return $cmd->execCmd();
    }

    /**
     * Récupère la valeur actuelle de batterie du robot. Contrairement à
     * l'humidité/température/météo, aucun tag de commande n'est à
     * configurer : le plugin principal (LandroidRTK.class.php) crée déjà
     * systématiquement une commande info "battery" sur chaque équipement
     * synchronisé depuis l'API Worx. On la réutilise donc directement.
     */
    private static function getBatteryValue($eqLogic) {
        $cmd = $eqLogic->getCmd(null, 'battery');
        return is_object($cmd) ? $cmd->execCmd() : null;
    }

    /**
     * Récupère la valeur de température actuelle depuis la commande
     * Jeedom configurée (temperature_cmd_id).
     */
    private static function getTemperatureValue($config) {
        if (empty($config['temperature_cmd_id'])) {
            return null;
        }
        $cmd = self::resolveCmd($config['temperature_cmd_id']);
        return is_object($cmd) ? $cmd->execCmd() : null;
    }

    /**
     * Résout et retourne la valeur actuelle d'un champ (tag ou nombre
     * fixe pour les heures), avec vérification de plage optionnelle.
     * Utilisé pour l'aperçu en direct à côté de chaque champ du
     * formulaire.
     */
    public static function previewValue($raw, $is_time_field = false, $min = null, $max = null) {
        if ($raw === null || $raw === '') {
            return array('value' => null, 'valid' => null, 'error' => null);
        }
        if ($is_time_field) {
            $resolved = self::resolveTimeField($raw);
            if ($resolved['mode'] == 'fixed') {
                if ($resolved['minutes'] === null) {
                    return array('value' => null, 'valid' => false, 'error' => 'Horaire invalide');
                }
                return array('value' => self::formatMinutes($resolved['minutes']) . ' (fixe)', 'valid' => true, 'error' => null);
            }
            if (!is_object($resolved['cmd'])) {
                return array('value' => null, 'valid' => false, 'error' => 'Commande introuvable');
            }
            // Sécurité : ne JAMAIS exécuter une commande d'action pendant
            // un simple aperçu (cela déclencherait réellement l'action,
            // ex: envoi d'une vraie notification vide). Seules les
            // commandes de type "info" ont une valeur "à prévisualiser".
            if ($resolved['cmd']->getType() != 'info') {
                return array('value' => 'commande action (existe)', 'valid' => true, 'error' => null);
            }
            $val = $resolved['cmd']->execCmd();
            $minutes = self::parseHM($val);
            if ($minutes === null) {
                return array('value' => $val, 'valid' => false, 'error' => 'Pas un horaire valide (HMM/HHMM attendu)');
            }
            return array('value' => $val . ' (' . self::formatMinutes($minutes) . ')', 'valid' => true, 'error' => null);
        }

        $cmd = self::resolveCmd($raw);
        if (!is_object($cmd)) {
            return array('value' => null, 'valid' => false, 'error' => 'Commande introuvable');
        }
        // Même règle de sécurité ici : jamais d'exécution d'une action.
        if ($cmd->getType() != 'info') {
            return array('value' => 'commande action (existe)', 'valid' => true, 'error' => null);
        }
        $val = $cmd->execCmd();
        $valid = true;
        $error = null;
        if ($min !== null && $max !== null) {
            if (!is_numeric($val) || $val < $min || $val > $max) {
                $valid = false;
                $error = "Hors limites ($min à $max)";
            }
        }
        return array('value' => $val, 'valid' => $valid, 'error' => $error);
    }

    private static function getConditionLabel($config) {
        if (empty($config['condition_cmd_id'])) {
            return '';
        }
        $val = self::getCmdValue($config['condition_cmd_id']);
        return $val ? (string) $val : '';
    }

    /**
     * Estimation "au mieux" de la prochaine tonte, en supposant que
     * l'humidité actuelle n'évolue pas. Ne garantit rien (la météo/pluie
     * peuvent toujours changer la donne), sert d'indication pratique pour
     * ajuster les réglages.
     */
    public static function estimateNextMow($eqLogic, $config) {
        $result = self::estimateNextMowCore($eqLogic, $config);
        if (!empty($result['text'])) {
            $state = self::getState($eqLogic);
            if (!empty($state['rain_interrupt_until']) && time() < $state['rain_interrupt_until']) {
                $result['text'] .= ' (tonte interrompue par la pluie)';
            }
        }
        return $result;
    }

    private static function estimateNextMowCore($eqLogic, $config) {
        $errors_warnings = self::checkConfig($eqLogic, $config, false);
        if (!empty($errors_warnings['errors'])) {
            return array('text' => null, 'error' => "Configuration invalide, impossible d'estimer.");
        }

        $state = self::getState($eqLogic);
        $now = time();
        $today = date('Y-m-d', $now);

        $start_resolved = self::resolveTimeField($config['time_start_cmd_id']);
        $end_resolved = self::resolveTimeField($config['time_end_cmd_id']);
        $start_min = ($start_resolved['mode'] == 'fixed') ? $start_resolved['minutes'] : self::parseHM(is_object($start_resolved['cmd']) ? $start_resolved['cmd']->execCmd() : null);
        $end_min = ($end_resolved['mode'] == 'fixed') ? $end_resolved['minutes'] : self::parseHM(is_object($end_resolved['cmd']) ? $end_resolved['cmd']->execCmd() : null);
        $latest_start = $end_min - intval($config['margin_minutes']);
        $now_minutes = intval(date('H', $now)) * 60 + intval(date('i', $now));

        // 1) Déjà tondu aujourd'hui : prochaine tonte au jour suivant éligible
        if ($state['last_mow_date'] == $today) {
            $next_date = date('Y-m-d', strtotime($state['last_mow_date'] . " +{$config['spacing_days']} days"));
            return array('text' => "Prochaine tonte prévue le " . date('d/m/Y', strtotime($next_date)) . " à partir de " . self::formatMinutes($start_min) . " (si conditions réunies).", 'error' => null);
        }

        // 2) Pas encore le jour (espacement pas respecté)
        if (!empty($state['last_mow_date'])) {
            $diff_days = (strtotime($today) - strtotime($state['last_mow_date'])) / 86400;
            if ($diff_days < intval($config['spacing_days'])) {
                $next_date = date('Y-m-d', strtotime($state['last_mow_date'] . " +{$config['spacing_days']} days"));
                return array('text' => "Prochaine tonte prévue le " . date('d/m/Y', strtotime($next_date)) . " à partir de " . self::formatMinutes($start_min) . " (si conditions réunies).", 'error' => null);
            }
        }

        // 3) Fenêtre horaire déjà fermée aujourd'hui : report à demain
        $window_closed_today = ($now_minutes > $latest_start);

        // 4) Humidité
        $humidity_val = self::getCmdValue($config['humidity_cmd_id']);
        $threshold = floatval($config['humidity_threshold']);
        $duration_needed = intval($config['humidity_duration_minutes']) * 60;

        if (!is_numeric($humidity_val) || floatval($humidity_val) > $threshold) {
            return array(
                'text' => "Indéterminé pour l'instant : l'humidité actuelle ($humidity_val%) doit d'abord repasser sous {$config['humidity_threshold']}%, puis y rester {$config['humidity_duration_minutes']} min, avant de pouvoir estimer une heure.",
                'error' => null,
            );
        }

        $humidity_ok_since = $now - (isset($state['humidity_low_since']) && $state['humidity_low_since'] !== null ? $state['humidity_low_since'] : $now);
        $remaining_seconds = $duration_needed - $humidity_ok_since;

        // Délai fixe (réglable) après une pluie ayant interrompu une
        // tonte : on ne peut jamais redémarrer avant, quoi qu'il arrive
        // côté humidité.
        if (!empty($state['rain_interrupt_until']) && $state['rain_interrupt_until'] > $now) {
            $remaining_seconds = max($remaining_seconds, $state['rain_interrupt_until'] - $now);
        }

        // 4bis) Température (optionnelle) : pas de prédiction fiable possible,
        // on indique juste si elle bloque actuellement (trop froid OU trop chaud).
        if (!empty($config['temperature_cmd_id'])) {
            $temp_val = self::getTemperatureValue($config);
            $temp_min = floatval($config['temperature_min']);
            $temp_max = floatval($config['temperature_max']);
            if (!is_numeric($temp_val) || floatval($temp_val) < $temp_min) {
                return array(
                    'text' => "Indéterminé pour l'instant : la température actuelle ({$temp_val}°C) est sous le seuil minimum de {$config['temperature_min']}°C.",
                    'error' => null,
                );
            }
            if (floatval($temp_val) > $temp_max) {
                return array(
                    'text' => "Indéterminé pour l'instant : la température actuelle ({$temp_val}°C) dépasse le seuil maximum de {$config['temperature_max']}°C (canicule).",
                    'error' => null,
                );
            }
        }

        // 4ter) Condition météo : pas de prédiction fiable possible (elle
        // peut changer à tout moment), on indique juste si elle bloque
        // actuellement, en précisant la condition en cours.
        $condition_id_est = self::getCmdValue($config['condition_id_cmd_id']);
        if (!self::isGoodWeather($condition_id_est)) {
            $condition_label_est = self::getConditionLabel($config);
            return array(
                'text' => "Indéterminé pour l'instant : toutes les autres conditions sont réunies, mais la condition météo actuelle" . ($condition_label_est !== '' ? " ($condition_label_est)" : '') . " ne correspond pas aux critères choisis pour tondre.",
                'error' => null,
            );
        }

        // 4quater) Batterie : pas de prédiction fiable possible non plus, on
        // indique juste si elle bloque actuellement.
        $battery_val = self::getBatteryValue($eqLogic);
        $battery_min = floatval($config['battery_min_percent']);
        if (!is_numeric($battery_val) || floatval($battery_val) < $battery_min) {
            return array(
                'text' => "Indéterminé pour l'instant : la batterie actuelle ($battery_val%) est sous le seuil minimum de {$config['battery_min_percent']}%.",
                'error' => null,
            );
        }

        if ($remaining_seconds <= 0 && !$window_closed_today && $now_minutes >= $start_min) {
            return array('text' => "Toutes les conditions semblent réunies — la tonte devrait démarrer au prochain passage (≤ 5 min), sous réserve d'un temps dégagé.", 'error' => null);
        }

        $eta = $now + max(0, $remaining_seconds);
        $eta_minutes = intval(date('H', $eta)) * 60 + intval(date('i', $eta));

        if ($window_closed_today || $eta_minutes > $latest_start) {
            $next_date = date('Y-m-d', strtotime('+1 day', $now));
            return array(
                'text' => "Trop tard pour aujourd'hui (fenêtre fermée à " . self::formatMinutes($latest_start) . ") — en supposant l'humidité inchangée, prochaine tentative le " . date('d/m/Y', strtotime($next_date)) . " à partir de " . self::formatMinutes($start_min) . ".",
                'error' => null,
            );
        }

        return array(
            'text' => "En supposant l'humidité inchangée, la tonte pourrait démarrer vers " . date('H:i', $eta) . " aujourd'hui (sous réserve d'un temps dégagé au moment venu).",
            'error' => null,
        );
    }

    /* ---------------------------------------------------------------- */
    /* Commandes du widget dashboard (programmation)                     */
    /* ---------------------------------------------------------------- */

    public static $WIDGET_COMMANDS = array(
        array('logicalId' => 'schedule_enabled',  'name' => 'Programmation',              'order' => 18),
        array('logicalId' => 'schedule_last_mow', 'name' => 'Dernière tonte',             'order' => 19),
        array('logicalId' => 'schedule_next_mow', 'name' => 'Prochaine tonte',            'order' => 20),
        array('logicalId' => 'schedule_margin',   'name' => 'Marge fin de journée (min)', 'order' => 21),
        array('logicalId' => 'schedule_spacing',  'name' => 'Espacement tontes (jours)',  'order' => 22),
        array('logicalId' => 'schedule_humidity_threshold', 'name' => "Seuil d'humidité (%)", 'order' => 23),
    );

    /**
     * Crée (si besoin) les commandes du widget liées à la programmation,
     * puis met à jour leurs valeurs à partir de la config JSON. Appelée
     * après chaque sauvegarde de la programmation ET par le cron.
     */
    /**
     * Réduit la taille d'affichage d'une commande sur le widget dashboard
     * (paramètre optionnel natif Jeedom "scale", accessible normalement via
     * Affichage > Paramètres optionnels sur la tuile d'une commande — ici
     * appliqué automatiquement pour que les tuiles ne soient pas 2x trop
     * grandes par défaut en plein écran).
     */
    private static function applyWidgetScale($cmd, $scale = 0.5) {
        $params = $cmd->getDisplay('parameters');
        if (!is_array($params)) {
            $params = array();
        }
        $params['scale'] = $scale;
        $cmd->setDisplay('parameters', $params);
        // setDisplay() doit être suivi d'un save() pour être persisté (s'il
        // a déjà été appelé une première fois juste avant, ce second appel
        // est sans risque, juste redondant).
        $cmd->save();
    }

    public static function syncWidgetCommands($eqLogic) {
        // IMPORTANT : chaque commande est créée si absente, PUIS ses
        // propriétés (nom, config, lien "commande info liée"...) sont
        // TOUJOURS réappliquées et sauvegardées, même si elle existait
        // déjà. Sans ça, une commande créée par une version antérieure
        // du plugin (avant l'ajout du lien slider<->info par exemple)
        // resterait figée avec son ancienne config pour toujours, même
        // après mise à jour du plugin — c'est ce qui causait le nom
        // figé et le curseur non lié constatés sur une install existante.

        // Info "Programmation" (Oui/Non, lecture seule) + 2 boutons
        // explicites Activer/Désactiver (plus lisible qu'une simple case
        // à cocher, comme les boutons Start/Stop/Maison existants).
        $cmd_enabled = $eqLogic->getCmd(null, 'schedule_enabled');
        if (!is_object($cmd_enabled)) {
            $cmd_enabled = new LandroidRTKCmd();
            $cmd_enabled->setLogicalId('schedule_enabled');
            $cmd_enabled->setEqLogic_id($eqLogic->getId());
        }
        $cmd_enabled->setName('Programmation');
        $cmd_enabled->setType('info');
        $cmd_enabled->setSubType('binary');
        $cmd_enabled->setGeneric_type('');
        $cmd_enabled->setOrder(30);
        $cmd_enabled->setDisplay('forceReturnLineAfter', '1');
        $cmd_enabled->save();
        self::applyWidgetScale($cmd_enabled);

        $cmd_activate = $eqLogic->getCmd(null, 'schedule_activate');
        if (!is_object($cmd_activate)) {
            $cmd_activate = new LandroidRTKCmd();
            $cmd_activate->setLogicalId('schedule_activate');
            $cmd_activate->setEqLogic_id($eqLogic->getId());
        }
        $cmd_activate->setName('Activer programmation');
        $cmd_activate->setType('action');
        $cmd_activate->setSubType('other');
        $cmd_activate->setGeneric_type('');
        $cmd_activate->setOrder(30);
        $cmd_activate->save();
        self::applyWidgetScale($cmd_activate);

        $cmd_deactivate = $eqLogic->getCmd(null, 'schedule_deactivate');
        if (!is_object($cmd_deactivate)) {
            $cmd_deactivate = new LandroidRTKCmd();
            $cmd_deactivate->setLogicalId('schedule_deactivate');
            $cmd_deactivate->setEqLogic_id($eqLogic->getId());
        }
        $cmd_deactivate->setName('Désactiver programmation');
        $cmd_deactivate->setType('action');
        $cmd_deactivate->setSubType('other');
        $cmd_deactivate->setGeneric_type('');
        $cmd_deactivate->setOrder(30);
        $cmd_deactivate->setDisplay('forceReturnLineAfter', '1');
        $cmd_deactivate->save();
        self::applyWidgetScale($cmd_deactivate);

        // Info "Dernière tonte" (texte, lecture seule)
        $cmd_last = $eqLogic->getCmd(null, 'schedule_last_mow');
        if (!is_object($cmd_last)) {
            $cmd_last = new LandroidRTKCmd();
            $cmd_last->setLogicalId('schedule_last_mow');
            $cmd_last->setEqLogic_id($eqLogic->getId());
        }
        $cmd_last->setName('Dernière tonte');
        $cmd_last->setType('info');
        $cmd_last->setSubType('string');
        $cmd_last->setGeneric_type('');
        $cmd_last->setOrder(31);
        $cmd_last->setDisplay('forceReturnLineAfter', '1');
        $cmd_last->save();
        self::applyWidgetScale($cmd_last);

        // Info "Prochaine tonte" (texte COURT, résumé pour le widget —
        // différent du texte long de l'onglet Programmation)
        $cmd_next = $eqLogic->getCmd(null, 'schedule_next_mow');
        if (!is_object($cmd_next)) {
            $cmd_next = new LandroidRTKCmd();
            $cmd_next->setLogicalId('schedule_next_mow');
            $cmd_next->setEqLogic_id($eqLogic->getId());
        }
        $cmd_next->setName('Prochaine tonte');
        $cmd_next->setType('info');
        $cmd_next->setSubType('string');
        $cmd_next->setGeneric_type('');
        $cmd_next->setOrder(32);
        $cmd_next->setDisplay('forceReturnLineAfter', '1');
        $cmd_next->save();
        self::applyWidgetScale($cmd_next);

        // Marge fin de journée : info numérique + action curseur (slider)
        $cmd_margin_info = $eqLogic->getCmd(null, 'schedule_margin');
        if (!is_object($cmd_margin_info)) {
            $cmd_margin_info = new LandroidRTKCmd();
            $cmd_margin_info->setLogicalId('schedule_margin');
            $cmd_margin_info->setEqLogic_id($eqLogic->getId());
        }
        $cmd_margin_info->setName('Marge fin de journée');
        $cmd_margin_info->setType('info');
        $cmd_margin_info->setSubType('numeric');
        $cmd_margin_info->setUnite('min');
        $cmd_margin_info->setGeneric_type('');
        $cmd_margin_info->setOrder(33);
        $cmd_margin_info->setDisplay('forceReturnLineAfter', '1');
        $cmd_margin_info->save();
        self::applyWidgetScale($cmd_margin_info);

        $cmd_margin_action = $eqLogic->getCmd(null, 'schedule_margin_set');
        if (!is_object($cmd_margin_action)) {
            $cmd_margin_action = new LandroidRTKCmd();
            $cmd_margin_action->setLogicalId('schedule_margin_set');
            $cmd_margin_action->setEqLogic_id($eqLogic->getId());
        }
        $cmd_margin_action->setName('Régler marge fin de journée');
        $cmd_margin_action->setType('action');
        $cmd_margin_action->setSubType('slider');
        $cmd_margin_action->setGeneric_type('');
        $cmd_margin_action->setOrder(33);
        $cmd_margin_action->setConfiguration('minValue', 0);
        $cmd_margin_action->setConfiguration('maxValue', 600);
        $cmd_margin_action->setConfiguration('updateCmdId', $cmd_margin_info->getId());
        $cmd_margin_action->setValue($cmd_margin_info->getId());
        $cmd_margin_action->setDisplay('forceReturnLineAfter', '1');
        $cmd_margin_action->save();
        self::applyWidgetScale($cmd_margin_action);

        // Espacement tontes : info numérique + action curseur (slider)
        $cmd_spacing_info = $eqLogic->getCmd(null, 'schedule_spacing');
        if (!is_object($cmd_spacing_info)) {
            $cmd_spacing_info = new LandroidRTKCmd();
            $cmd_spacing_info->setLogicalId('schedule_spacing');
            $cmd_spacing_info->setEqLogic_id($eqLogic->getId());
        }
        $cmd_spacing_info->setName('Espacement tontes');
        $cmd_spacing_info->setType('info');
        $cmd_spacing_info->setSubType('numeric');
        $cmd_spacing_info->setUnite('j');
        $cmd_spacing_info->setGeneric_type('');
        $cmd_spacing_info->setOrder(34);
        $cmd_spacing_info->setDisplay('forceReturnLineAfter', '1');
        $cmd_spacing_info->save();
        self::applyWidgetScale($cmd_spacing_info);

        $cmd_spacing_action = $eqLogic->getCmd(null, 'schedule_spacing_set');
        if (!is_object($cmd_spacing_action)) {
            $cmd_spacing_action = new LandroidRTKCmd();
            $cmd_spacing_action->setLogicalId('schedule_spacing_set');
            $cmd_spacing_action->setEqLogic_id($eqLogic->getId());
        }
        $cmd_spacing_action->setName('Régler espacement tontes');
        $cmd_spacing_action->setType('action');
        $cmd_spacing_action->setSubType('slider');
        $cmd_spacing_action->setGeneric_type('');
        $cmd_spacing_action->setOrder(34);
        $cmd_spacing_action->setConfiguration('minValue', 1);
        $cmd_spacing_action->setConfiguration('maxValue', 28);
        $cmd_spacing_action->setConfiguration('updateCmdId', $cmd_spacing_info->getId());
        $cmd_spacing_action->setValue($cmd_spacing_info->getId());
        $cmd_spacing_action->setDisplay('forceReturnLineAfter', '1');
        $cmd_spacing_action->save();
        self::applyWidgetScale($cmd_spacing_action);

        // Seuil d'humidité : info numérique + action curseur (slider 0-100)
        $cmd_humidity_info = $eqLogic->getCmd(null, 'schedule_humidity_threshold');
        if (!is_object($cmd_humidity_info)) {
            $cmd_humidity_info = new LandroidRTKCmd();
            $cmd_humidity_info->setLogicalId('schedule_humidity_threshold');
            $cmd_humidity_info->setEqLogic_id($eqLogic->getId());
        }
        $cmd_humidity_info->setName("Seuil d'humidité");
        $cmd_humidity_info->setType('info');
        $cmd_humidity_info->setSubType('numeric');
        $cmd_humidity_info->setUnite('%');
        $cmd_humidity_info->setGeneric_type('');
        $cmd_humidity_info->setOrder(35);
        $cmd_humidity_info->setDisplay('forceReturnLineAfter', '1');
        $cmd_humidity_info->save();
        self::applyWidgetScale($cmd_humidity_info);

        $cmd_humidity_action = $eqLogic->getCmd(null, 'schedule_humidity_threshold_set');
        if (!is_object($cmd_humidity_action)) {
            $cmd_humidity_action = new LandroidRTKCmd();
            $cmd_humidity_action->setLogicalId('schedule_humidity_threshold_set');
            $cmd_humidity_action->setEqLogic_id($eqLogic->getId());
        }
        $cmd_humidity_action->setName("Régler seuil d'humidité");
        $cmd_humidity_action->setType('action');
        $cmd_humidity_action->setSubType('slider');
        $cmd_humidity_action->setGeneric_type('');
        $cmd_humidity_action->setOrder(35);
        $cmd_humidity_action->setConfiguration('minValue', 0);
        $cmd_humidity_action->setConfiguration('maxValue', 100);
        $cmd_humidity_action->setConfiguration('updateCmdId', $cmd_humidity_info->getId());
        $cmd_humidity_action->setValue($cmd_humidity_info->getId());
        $cmd_humidity_action->setDisplay('forceReturnLineAfter', '1');
        $cmd_humidity_action->save();
        self::applyWidgetScale($cmd_humidity_action);

        // Nettoyage des commandes widget obsolètes : si une ancienne
        // version du plugin utilisait un logicalId différent pour une
        // même fonction (ex: un unique bouton "Activer/désactiver"
        // avant l'introduction des 2 boutons séparés actuels), la
        // commande orpheline resterait sinon indéfiniment affichée en
        // double sur le widget. On supprime donc tout ce qui commence
        // par "schedule_" et qui ne fait pas partie de la liste actuelle.
        $valid_logical_ids = array(
            'schedule_enabled', 'schedule_activate', 'schedule_deactivate',
            'schedule_last_mow', 'schedule_next_mow',
            'schedule_margin', 'schedule_margin_set',
            'schedule_spacing', 'schedule_spacing_set',
            'schedule_humidity_threshold', 'schedule_humidity_threshold_set',
        );
        foreach ($eqLogic->getCmd() as $existing_cmd) {
            $lid = $existing_cmd->getLogicalId();
            if (strpos($lid, 'schedule_') === 0 && !in_array($lid, $valid_logical_ids)) {
                log::add('LandroidRTK', 'info', 'Scheduler: suppression de la commande widget obsolète "' . $existing_cmd->getName() . '" (logicalId=' . $lid . ') sur ' . $eqLogic->getHumanName() . '.');
                $existing_cmd->remove();
            }
        }

        // Mise à jour des valeurs affichées à partir de la config actuelle
        $config = self::getConfig($eqLogic);
        $state = self::getState($eqLogic);

        $eqLogic->checkAndUpdateCmd('schedule_enabled', ($config['enabled'] == '1') ? 1 : 0);
        $eqLogic->checkAndUpdateCmd('schedule_margin', $config['margin_minutes']);
        $eqLogic->checkAndUpdateCmd('schedule_spacing', $config['spacing_days']);
        $eqLogic->checkAndUpdateCmd('schedule_humidity_threshold', $config['humidity_threshold']);
        $eqLogic->checkAndUpdateCmd(
            'schedule_last_mow',
            !empty($state['last_mow_date']) ? date('d/m/Y', strtotime($state['last_mow_date'])) : 'Jamais'
        );
        $eqLogic->checkAndUpdateCmd('schedule_next_mow', self::estimateNextMowShort($eqLogic, $config));

        // La position visuelle d'un curseur (widget "slider") sur le
        // dashboard suit l'état propre de SA commande action (et non
        // celui de la commande info liée) : quand la valeur change par
        // un autre biais que le curseur lui-même (ici : sauvegarde
        // depuis l'onglet Programmation), il faut donc aussi rafraîchir
        // explicitement l'état de la commande action via event(), sans
        // quoi le chiffre affiché change mais le curseur reste figé.
        if (is_object($cmd_margin_action)) {
            $cmd_margin_action->event($config['margin_minutes']);
        }
        if (is_object($cmd_spacing_action)) {
            $cmd_spacing_action->event($config['spacing_days']);
        }
        if (is_object($cmd_humidity_action)) {
            $cmd_humidity_action->event($config['humidity_threshold']);
        }
    }

    /**
     * Version COURTE de l'estimation de prochaine tonte, pour le widget
     * (l'onglet Programmation, lui, affiche la version longue et détaillée
     * via estimateNextMow()).
     */
    public static function estimateNextMowShort($eqLogic, $config) {
        if (empty($config['enabled']) || $config['enabled'] != '1') {
            return 'Programmation désactivée';
        }
        $full = self::estimateNextMow($eqLogic, $config);
        if (empty($full['text'])) {
            return $full['error'] ? 'Config invalide' : '?';
        }
        $state = self::getState($eqLogic);
        $today = date('Y-m-d');
        $rain_suffix = (!empty($state['rain_interrupt_until']) && time() < $state['rain_interrupt_until']) ? ' (pluie)' : '';

        if ($state['last_mow_date'] == $today) {
            return 'Déjà tondu aujourd\'hui';
        }
        if (strpos($full['text'], 'Indéterminé') !== false) {
            return (strpos($full['text'], 'température') !== false) ? 'Indéterminé (température trop basse)' : 'Indéterminé (humidité trop élevée)';
        }
        if (preg_match('/vers (\d{2}:\d{2}) aujourd\'hui/', $full['text'], $m)) {
            return $m[1] . ' aujourd\'hui' . $rain_suffix;
        }
        if (preg_match('/le (\d{2}\/\d{2}\/\d{4}) à partir de (\d{2}:\d{2})/', $full['text'], $m)) {
            return $m[1] . ' ' . $m[2] . $rain_suffix;
        }
        if (strpos($full['text'], 'devrait démarrer au prochain passage') !== false) {
            return 'Imminent (< 5 min)' . $rain_suffix;
        }
        return mb_substr($full['text'], 0, 40) . '...';
    }

    /**
     * Traite les actions déclenchées depuis les widgets du dashboard
     * (curseurs marge/espacement, bouton ON/OFF programmation). Appelée
     * depuis LandroidRTKCmd::execute() pour ces logicalId précis.
     */
    public static function handleWidgetAction($eqLogic, $logicalId, $value) {
        $config = self::getConfig($eqLogic);

        if ($logicalId == 'schedule_margin_set') {
            $config['margin_minutes'] = intval($value);
        } elseif ($logicalId == 'schedule_spacing_set') {
            $config['spacing_days'] = max(1, min(28, intval($value)));
        } elseif ($logicalId == 'schedule_humidity_threshold_set') {
            $config['humidity_threshold'] = max(0, min(100, intval($value)));
        } elseif ($logicalId == 'schedule_activate' || $logicalId == 'schedule_deactivate') {
            $want_enable = ($logicalId == 'schedule_activate');
            if ($want_enable) {
                $result = self::checkConfig($eqLogic, $config, false);
                if (!empty($result['errors'])) {
                    log::add('LandroidRTK', 'error', 'Impossible d\'activer la programmation (' . $eqLogic->getHumanName() . ') : ' . implode(' | ', $result['errors']));
                    try {
                        message::add('LandroidRTK', 'Impossible d\'activer la programmation de "' . $eqLogic->getName() . '" : configuration incomplète (voir onglet Programmation pour le détail).');
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
}
