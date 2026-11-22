<?php

/**
 * @file plugins/generic/dossierManager/classes/Settings.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Settings
 *
 * @brief The plugin's journal settings with their defaults, and the per-dossier
 *  overrides of the reminder settings.
 */

namespace APP\plugins\generic\dossierManager\classes;

use PKP\db\DAORegistry;

class Settings
{
    const PLUGIN_NAME = 'dossiermanagerplugin';

    const REMINDER_DAYS = 'reminderDays';
    const OVERDUE_INTERVAL = 'overdueInterval';
    const OVERDUE_MAX = 'overdueMax';
    const ALLOW_DECLINE = 'allowDecline';
    const SUPPRESS_EMAILS = 'suppressEmails';
    /** Newline-separated list of dossier types (labels) */
    const TYPES = 'dossierTypes';

    const DEFAULTS = [
        self::REMINDER_DAYS => 7,
        self::OVERDUE_INTERVAL => 7,
        self::OVERDUE_MAX => 4,
        self::ALLOW_DECLINE => true,
        self::SUPPRESS_EMAILS => false,
    ];

    /** Locale keys of the default dossier types */
    const DEFAULT_TYPES = ['specialIssue', 'thematicSection', 'reviewSymposium', 'forum'];

    public static function get(int $contextId, string $name): mixed
    {
        $value = DAORegistry::getDAO('PluginSettingsDAO')->getSetting($contextId, self::PLUGIN_NAME, $name);
        if ($name === self::TYPES) {
            return $value === null || trim((string) $value) === '' ? self::defaultTypes() : self::parseTypes((string) $value);
        }
        return $value ?? (self::DEFAULTS[$name] ?? null);
    }

    public static function set(int $contextId, string $name, mixed $value): void
    {
        $type = is_bool($value) ? 'bool' : (is_int($value) ? 'int' : 'string');
        DAORegistry::getDAO('PluginSettingsDAO')->updateSetting($contextId, self::PLUGIN_NAME, $name, $value, $type);
    }

    /** @return string[] */
    public static function defaultTypes(): array
    {
        return array_map(fn ($key) => __('plugins.generic.dossierManager.type.' . $key), self::DEFAULT_TYPES);
    }

    /** @return string[] */
    public static function parseTypes(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value))));
    }

    /**
     * Reminder settings for a dossier: its own values, else the journal's.
     * @return array [daysBefore, interval, max]
     */
    public static function reminderSettings(Dossier $dossier): array
    {
        return [
            $dossier->reminderDays ?? (int) self::get($dossier->contextId, self::REMINDER_DAYS),
            $dossier->overdueInterval ?? (int) self::get($dossier->contextId, self::OVERDUE_INTERVAL),
            $dossier->overdueMax ?? (int) self::get($dossier->contextId, self::OVERDUE_MAX),
        ];
    }

    public static function emailsSuppressed(int $contextId): bool
    {
        return (bool) self::get($contextId, self::SUPPRESS_EMAILS);
    }
}
