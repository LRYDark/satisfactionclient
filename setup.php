<?php

define('PLUGIN_SATISFACTIONCLIENT_VERSION', '1.1.3');
define('PLUGIN_SATISFACTIONCLIENT_MIN_GLPI', '11.0.0');
define('PLUGIN_SATISFACTIONCLIENT_MAX_GLPI', '11.0.99');

function plugin_init_satisfactionclient()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['satisfactionclient'] = true;

    $plugin = new Plugin();
    if ($plugin->isInstalled('satisfactionclient') && $plugin->isActivated('satisfactionclient')) {
        $PLUGIN_HOOKS['post_show_item']['satisfactionclient'] = ['PluginSatisfactionclientSurvey', 'postShowItem'];
        $PLUGIN_HOOKS['item_add']['satisfactionclient'] = [
            'ITILFollowup' => ['PluginSatisfactionclientSurvey', 'handleFollowupAdd'],
        ];
        $PLUGIN_HOOKS['config_page']['satisfactionclient'] = 'front/config.form.php';
    }
}

function plugin_version_satisfactionclient()
{
    return [
        'name'         => __('Satisfaction client', 'satisfactionclient'),
        'version'      => PLUGIN_SATISFACTIONCLIENT_VERSION,
        'author'       => 'REINERT Joris',
        'license'      => 'GPLv3',
        'homepage'     => 'https://example.invalid',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SATISFACTIONCLIENT_MIN_GLPI,
                'max' => PLUGIN_SATISFACTIONCLIENT_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_satisfactionclient_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_SATISFACTIONCLIENT_MIN_GLPI, '<')) {
        return false;
    }
    if (version_compare(GLPI_VERSION, PLUGIN_SATISFACTIONCLIENT_MAX_GLPI, '>=')) {
        return false;
    }
    return true;
}

function plugin_satisfactionclient_check_config($verbose = false)
{
    return true;
}
