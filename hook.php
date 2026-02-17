<?php

function plugin_satisfactionclient_install()
{
    include_once __DIR__ . '/sql/install.php';
    return PluginSatisfactionclientInstall::install();
}

function plugin_satisfactionclient_uninstall()
{
    include_once __DIR__ . '/sql/uninstall.php';
    return PluginSatisfactionclientInstall::uninstall();
}
