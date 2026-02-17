<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginSatisfactionclientConfig
{
    private const TABLE = 'glpi_plugin_satisfactionclient_configs';
    private const DEFAULT_INTRO_TEXT = 'Merci de noter le service.';

    public static function ensureDefaults(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        $dbu = new DbUtils();
        if ($dbu->countElementsInTable(self::TABLE, []) > 0) {
            return;
        }

        $DB->insert(self::TABLE, [
            'intro_text' => self::DEFAULT_INTRO_TEXT,
        ]);
    }

    public static function getIntroText(): string
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return self::DEFAULT_INTRO_TEXT;
        }

        self::ensureDefaults();

        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            $text = trim((string) ($row['intro_text'] ?? ''));
            return $text !== '' ? $text : self::DEFAULT_INTRO_TEXT;
        }

        return self::DEFAULT_INTRO_TEXT;
    }

    public static function updateIntroText(string $text): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return false;
        }

        $text = trim($text);
        if ($text === '') {
            $text = self::DEFAULT_INTRO_TEXT;
        }

        self::ensureDefaults();

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::TABLE,
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return (bool) $DB->update(self::TABLE, ['intro_text' => $text], ['id' => (int) $row['id']]);
        }

        return (bool) $DB->insert(self::TABLE, ['intro_text' => $text]);
    }
}
