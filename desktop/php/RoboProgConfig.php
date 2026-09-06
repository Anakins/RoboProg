<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<div role="tabpanel" class="tab-pane" id="scheduletab">
    <div style="text-align:left; margin:10px;">
        <a class="btn btn-default" id="bt_testConfig"><i class="fas fa-flask"></i> Tester la configuration</a>
        <a class="btn btn-success" id="bt_saveConfig"><i class="fas fa-check-circle"></i> Sauvegarder la programmation</a>
    </div>

    <div id="rp_next_mow" class="alert alert-info" style="display:none; margin:10px;"></div>
    <div class="alert alert-warning" style="margin:10px;">
        <i class="fas fa-exclamation-triangle"></i>
        Cet onglet a son <strong>propre bouton "Sauvegarder"</strong> ci-dessus, en haut à gauche — le bouton "Sauvegarder" en haut à droite de la page (natif Jeedom) ne s'applique qu'à l'onglet "Équipement" et n'enregistrera pas cette programmation.
    </div>

    <div id="rp_conditions_status" style="display:none; margin:10px; border:1px solid #ddd; border-radius:4px; padding:10px; background:#fff;">
        <strong><i class="fas fa-list-check"></i> État des conditions de démarrage</strong>
        <a class="pull-right cursor" id="bt_refreshConditionsStatus" title="Rafraîchir"><i class="fas fa-sync"></i></a>
        <table class="table table-condensed" style="margin-top:8px; margin-bottom:0; table-layout:fixed; width:100%;">
            <thead>
                <tr>
                    <th style="width:50%;">Condition</th>
                    <th style="width:50%;">État</th>
                </tr>
            </thead>
            <tbody id="rp_conditions_status_body"></tbody>
        </table>
    </div>

    <div id="div_alert" class="alert" style="display:none; margin:10px;"></div>

    <div class="alert alert-default" style="margin:10px; border:1px solid #ddd;">
        <strong><i class="fas fa-tools"></i> Outils de débogage</strong>
        <div style="margin-top:8px;">
            <a class="btn btn-warning btn-sm" id="bt_debugMowYesterday"><i class="fas fa-bug"></i> [Débogage] Régler la dernière tonte à hier</a>
            <span class="help-block" style="display:inline-block; margin:4px 0 10px 0;">Permet de tester le déclenchement le jour même sans attendre l'espacement complet. À utiliser uniquement pour vérifier que le robot démarre bien selon les seuils paramétrés.</span>
        </div>
        <div>
            <a class="btn btn-default btn-sm" id="bt_markMowToday"><i class="fas fa-check"></i> Marquer la tonte d'aujourd'hui comme faite</a>
            <span class="help-block" style="display:inline-block; margin:4px 0 0 0;">À utiliser si vous avez lancé une tonte manuellement (hors programmation).</span>
        </div>
        <div style="margin-top:8px;">
            <a class="btn btn-default btn-sm" id="bt_resetNotifThrottle"><i class="fas fa-bell-slash"></i> Réinitialiser l'anti-doublon des notifications "pas de tonte"</a>
            <span class="help-block" style="display:inline-block; margin:4px 0 0 0;">Débloque l'envoi immédiat, sans attendre le lendemain.</span>
        </div>
    </div>

    <form class="form-horizontal" id="roboprog_form">

    <fieldset>
        <legend><i class="fas fa-power-off"></i> Activation</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Nom du robot</label>
            <div class="col-sm-6">
                <input type="text" id="rp_robot_name" class="form-control" placeholder="ex: Bob">
                <span class="help-block">Obligatoire (au moins une lettre). Utilisé dans les notifications et les logs, à la place du nom technique de l'équipement Jeedom.</span>
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
                <div class="checkbox">
                    <label><input type="checkbox" id="rp_enabled"> Activer la programmation</label>
                </div>
                <div class="help-block">Ne peut être activée que si le bouton "Tester" ci-dessus ne renvoie aucune erreur.</div>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-robot"></i> Commande robot obligatoire</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande pour lancer la tonte</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_start_cmd_id" class="form-control" placeholder="#[Objet][Équipement robot][Démarrer]#">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_start_cmd_id" data-cmdtype="action"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Doit obligatoirement pointer vers une commande <b>action</b> Jeedom (celle qui fait démarrer le robot).</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_start_cmd_id"></span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-unlock"></i> Commandes optionnelles</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande de retour à la maison</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_home_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_home_cmd_id" data-cmdtype="action"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Optionnel, mais nécessaire pour deux usages : 1) rappeler le robot en cas de pluie détectée (nécessite aussi un capteur pluie configuré — sans capteur pluie, la pluie n'est simplement jamais détectée, et cette commande ne sert alors qu'au point 2) ; 2) la relance automatique du cycle classique après un rattrapage de bordures (avec Statut + valeur "à la maison" enregistrée, voir plus bas). Si vous ne voulez pas gérer la pluie du tout, laissez simplement le capteur pluie ET/OU cette commande vides.</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_home_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
                <div class="checkbox">
                    <label><input type="checkbox" id="rp_edge_enabled"> Mon robot ne fait pas les bordures automatiquement (commande dédiée)</label>
                </div>
                <div class="help-block">À cocher uniquement si votre robot nécessite une commande séparée pour les bordures. Débloque le champ ci-dessous et toute la section "Programmation des bordures".</div>
            </div>
        </div>
        <div class="form-group" id="fs_edge_cmd" style="display:none;">
            <label class="col-sm-3 control-label">Commande pour lancer la tonte des bordures</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_edge_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_edge_cmd_id" data-cmdtype="action"><i class="fa fa-list-alt"></i></a></span>
                </div>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_edge_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande de statut</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_status_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_status_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Info Jeedom donnant l'état courant du robot. Nécessaire (avec Maison) pour la relance automatique après bordures.</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_status_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande d'erreur</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_error_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_error_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">N'importe quelle valeur (vide ou texte) : juste informatif, affiché tel quel.</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_error_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande de batterie</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_battery_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_battery_cmd_id" data-cmdtype="info" data-cmdsubtype="numeric"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Info numérique (%). Si renseignée, vérifiée avant chaque envoi de commande de démarrage.</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_battery_cmd_id" data-min="0" data-max="100"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Seuil minimum batterie</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_battery_min_percent" class="form-control" min="0" max="100" style="width:80px;">
                <span>%</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label"></label>
            <div class="col-sm-9">
                <a class="btn btn-default btn-sm" id="bt_recordHomeStatus"><i class="fas fa-map-marker-alt"></i> Enregistrer le statut actuel comme "à la maison"</a>
                <span id="rp_home_status_check" style="margin-left:10px;"></span>
                <div class="help-block">À utiliser quand le robot est physiquement à la base. Nécessaire pour la relance automatique après bordures (le retour forcé en cas de pluie, lui, ne s'appuie pas sur ce statut : il attend simplement le délai fixe réglé plus bas).</div>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-clock"></i> Plage horaire et espacement</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande (ou heure fixe) de début</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_time_start_cmd_id" class="form-control" placeholder="ex: #[Extérieur][Météo][Lever du soleil]# ou 800">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_time_start_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Tag de commande Jeedom, ou heure fixe au format HMM/HHMM (ex: 800 = 08h00).</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_time_start_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande (ou heure fixe) de fin</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_time_end_cmd_id" class="form-control" placeholder="ex: 2000">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_time_end_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_time_end_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Marge avant l'heure de fin</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_margin_minutes" class="form-control" min="0" max="600" style="width:80px;">
                <span>min (0 à 600)</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" id="rp_latest_start_preview"></span>
            </div>
            <div class="col-sm-3"></div>
            <div class="col-sm-9">
                <span class="help-block">Le robot ne démarrera plus une tonte si elle risque de se terminer après (heure de fin − cette marge).</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Espacement entre tontes</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_spacing_days" class="form-control" min="1" max="30" style="width:80px;">
                <span>jour(s)</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-tint"></i> Météo (obligatoire)</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande d'humidité</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_humidity_cmd_id" class="form-control" placeholder="via un plugin météo">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_humidity_cmd_id" data-cmdtype="info" data-cmdsubtype="numeric"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Obligatoire. Doit provenir d'un plugin météo tiers (capteur externe).</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_humidity_cmd_id" data-min="0" data-max="100"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Seuil d'humidité</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_humidity_threshold" class="form-control" min="0" max="100" style="width:80px;">
                <span>%</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Délai d'humidité</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_humidity_duration_minutes" class="form-control" min="0" style="width:80px;">
                <span>min</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande code météo</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_condition_id_cmd_id" class="form-control" placeholder="via un plugin météo">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_condition_id_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Obligatoire. Le code numérique de la condition météo actuelle (convention OpenWeatherMap), fourni par votre plugin météo.</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_condition_id_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande condition météo</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_condition_cmd_id" class="form-control" placeholder="via un plugin météo">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_condition_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Obligatoire. Le libellé de la condition météo actuelle (informatif, affiché dans les notifications/logs).</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_condition_cmd_id"></span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-thermometer-half"></i> Température (optionnel)</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande de température</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_temperature_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_temperature_cmd_id" data-cmdtype="info" data-cmdsubtype="numeric"><i class="fa fa-list-alt"></i></a></span>
                </div>
                <span class="help-block">Laisser vide pour ignorer ce critère.</span>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_temperature_cmd_id" data-min="-50" data-max="80"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Seuil minimum</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_temperature_min" class="form-control" min="4" max="18" style="width:80px;">
                <span>°C (4 à 18)</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Seuil maximum</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_temperature_max" class="form-control" min="30" max="50" style="width:80px;">
                <span>°C (30 à 50)</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-cloud-rain"></i> Pluie (optionnel)</legend>
        <div class="alert alert-info" style="margin:0 15px 10px;">
            Pas de convention universelle possible ici (contrairement à un robot dédié) : indiquez vous-même à quelle valeur correspond "il pleut" pour votre commande. Deux capteurs possibles (l'un ou l'autre suffit à détecter la pluie), utilisables librement : le capteur du robot, un capteur externe, ou les deux.
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande capteur 1</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_rain_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_rain_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_rain_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Condition "il pleut"</label>
            <div class="col-sm-2">
                <select id="rp_rain_operator" class="form-control">
                    <option value="==">est égal à</option>
                    <option value="!=">est différent de</option>
                </select>
            </div>
            <div class="col-sm-4">
                <input type="text" id="rp_rain_value" class="form-control" placeholder="valeur (ex: 1, oui...)">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Commande capteur 2 (optionnel)</label>
            <div class="col-sm-6">
                <div class="input-group">
                    <input type="text" id="rp_rain_extra_cmd_id" class="form-control">
                    <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#rp_rain_extra_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                </div>
            </div>
            <div class="col-sm-3" style="padding-top:7px;">
                <span class="cmdValuePreview text-muted" data-input="#rp_rain_extra_cmd_id"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Condition "il pleut" (2)</label>
            <div class="col-sm-2">
                <select id="rp_rain_extra_operator" class="form-control">
                    <option value="==">est égal à</option>
                    <option value="!=">est différent de</option>
                </select>
            </div>
            <div class="col-sm-4">
                <input type="text" id="rp_rain_extra_value" class="form-control" placeholder="valeur (ex: 1, oui...)">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Durée de tonte estimée</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_mow_duration_minutes" class="form-control" min="30" max="360" style="width:80px;">
                <span>min (30 à 360, soit 6h max)</span>
            </div>
            <div class="col-sm-3"></div>
            <div class="col-sm-9">
                <span class="help-block">Sert à distinguer une vraie pluie pendant la tonte (retour forcé + tonte invalidée) d'une fausse alerte plus tard le même jour, sans rapport avec une tonte déjà terminée depuis longtemps (utile notamment pour les robots sans garage, dont le capteur peut se déclencher des heures après coup). Par défaut : 120 min.</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">Délai avant redémarrage après pluie</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_rain_interrupt_minutes" class="form-control" min="20" max="120" style="width:80px;">
                <span>min (20 à 120)</span>
            </div>
        </div>
    </fieldset>

    <fieldset id="fs_edge" style="display:none;">
        <legend><i class="fas fa-border-style"></i> Programmation des bordures</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Mode</label>
            <div class="col-sm-6">
                <label class="radio-inline"><input type="radio" name="rp_edge_mode" value="interval"> Intervalle</label>
                <label class="radio-inline"><input type="radio" name="rp_edge_mode" value="weekday"> Jours de la semaine</label>
            </div>
        </div>
        <div class="form-group" id="fs_edge_interval">
            <label class="col-sm-3 control-label">Intervalle</label>
            <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="rp_edge_interval_days" class="form-control" min="1" max="7" style="width:80px;">
                <span>jour(s) (1 à 7 — 7 = toutes les semaines)</span>
            </div>
        </div>
        <div class="form-group" id="fs_edge_weekday" style="display:none;">
            <label class="col-sm-3 control-label">Jours</label>
            <div class="col-sm-9">
                <label class="checkbox-inline"><input type="checkbox" class="rp_edge_weekday" value="1"> Lundi</label>
                <label class="checkbox-inline"><input type="checkbox" class="rp_edge_weekday" value="2"> Mardi</label>
                <label class="checkbox-inline"><input type="checkbox" class="rp_edge_weekday" value="3"> Mercredi</label>
                <label class="checkbox-inline"><input type="checkbox" class="rp_edge_weekday" value="4"> Jeudi</label>
                <label class="checkbox-inline"><input type="checkbox" class="rp_edge_weekday" value="5"> Vendredi</label>
                <label class="checkbox-inline"><input type="checkbox" class="rp_edge_weekday" value="6"> Samedi</label>
                <label class="checkbox-inline"><input type="checkbox" class="rp_edge_weekday" value="7"> Dimanche</label>
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
                <div class="checkbox">
                    <label><input type="checkbox" id="rp_edge_catchup_enabled"> Faire les bordures en rattrapage si le dernier passage prévu n'a pas eu lieu</label>
                </div>
            </div>
        </div>
        <div class="form-group" id="fs_edge_resume">
            <div class="col-sm-9 col-sm-offset-3">
                <div class="checkbox">
                    <label><input type="checkbox" id="rp_edge_resume_enabled"> Après une coupe de bordures — rattrapage ou jour normal programmé — relancer automatiquement le cycle de tonte classique une fois le robot de retour à la maison (batterie OK)</label>
                </div>
                <div class="help-block">Nécessite les commandes de retour à la maison + Statut, et d'avoir enregistré le statut "à la maison" ci-dessus. <strong>Obligatoire si les bordures sont programmées tous les jours</strong> (sinon la tonte classique ne pourrait jamais avoir lieu).</div>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-bell"></i> Notifications</legend>
        <ul style="margin:0 0 10px 15px; padding:0; font-size:0.9em; color:#666;">
            <li><b>Pas de tonte</b> : envoyée en fin de journée si la tonte classique n'a pas pu avoir lieu.</li>
            <li><b>Erreur</b> : envoyée si le robot signale une erreur (commande Erreur liée).</li>
            <li><b>Rattrapage</b> : envoyée quand des bordures manquées sont rattrapées.</li>
            <li><b>Relance</b> : envoyée quand la tonte classique redémarre automatiquement après des bordures.</li>
        </ul>
        <table class="table table-condensed" id="table_notifications">
            <thead>
                <tr>
                    <th style="width:25%;">Commande</th>
                    <th style="width:18%;">Titre</th>
                    <th style="width:11%;">HTML (&lt;br/&gt;)</th>
                    <th style="width:15%;">Pas de tonte</th>
                    <th style="width:15%;">Erreur</th>
                    <th style="width:16%;">Bordures</th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <a class="btn btn-default btn-sm" id="bt_addNotification"><i class="fas fa-plus"></i> Ajouter une notification</a>
        <table style="display:none;">
            <tr class="notificationTemplate">
                <td>
                    <div class="input-group">
                        <input type="text" class="form-control notif_cmd_id">
                        <span class="input-group-btn">
                            <a class="btn btn-success bt_openCmdPicker" data-target-self="1" data-cmdtype="action"><i class="fa fa-list-alt"></i></a>
                        </span>
                    </div>
                    <span class="cmdValuePreview text-muted"></span>
                </td>
                <td><input type="text" class="form-control notif_title" placeholder="(nom du robot) - TONTE"></td>
                <td style="text-align:center;"><input type="checkbox" class="notif_html"></td>
                <td style="text-align:center;"><input type="checkbox" class="notif_no_mow" checked></td>
                <td style="text-align:center;"><input type="checkbox" class="notif_error"></td>
                <td style="text-align:center;">
                    <label style="display:block;"><input type="checkbox" class="notif_edge_catchup"> Rattrapage</label>
                    <label style="display:block;"><input type="checkbox" class="notif_edge_resume"> Relance</label>
                </td>
                <td>
                    <a class="btn btn-default btn-xs bt_testNotifRow"><i class="fas fa-paper-plane"></i></a>
                    <a class="btn btn-danger btn-xs bt_removeRow"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
        </table>
    </fieldset>

    </form>
</div>
