<?php

function RoboProg_install() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    // Ne régénère la clé QUE si elle n'existe pas encore : sinon, chaque
    // simple désactivation/réactivation invaliderait la clé déjà connue
    // du JS déjà chargé dans un navigateur ouvert.
    if (config::byKey('api', $pluginId, '') == '') {
        config::save('api', config::genKey(), $pluginId);
        config::save("api::{$pluginId}::mode", 'localhost');
        config::save("api::{$pluginId}::restricted", 1);
    }
    config::save('log::level::RoboProg', '{"100":"1","200":"0","300":"0","400":"0","1000":"0","default":"0"}');
    log::add('RoboProg', 'info', 'Installation du plugin RoboProg.');
}

function RoboProg_update() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    if (config::byKey('api', $pluginId, '') == '') {
        config::save('api', config::genKey(), $pluginId);
        config::save("api::{$pluginId}::mode", 'localhost');
        config::save("api::{$pluginId}::restricted", 1);
    }
    config::save('log::level::RoboProg', '{"100":"1","200":"0","300":"0","400":"0","1000":"0","default":"0"}');
    log::add('RoboProg', 'info', 'Mise à jour du plugin RoboProg.');
}

function RoboProg_remove() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    config::remove('api', $pluginId);
    config::remove("api::{$pluginId}::mode");
    config::remove("api::{$pluginId}::restricted");
    log::add('RoboProg', 'info', 'Suppression du plugin RoboProg.');
}
