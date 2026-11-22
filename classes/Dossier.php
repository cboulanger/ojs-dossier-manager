<?php

/**
 * @file plugins/generic/dossierManager/classes/Dossier.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Dossier
 *
 * @brief A planned group of commissioned articles (special issue, thematic
 *  section, review symposium, ...). See DossierRepository for storage.
 */

namespace APP\plugins\generic\dossierManager\classes;

class Dossier
{
    public ?int $id = null;
    public int $contextId = 0;
    public string $type = '';
    /** @var array<string,string> locale => title */
    public array $title = [];
    /** @var array<string,string> locale => description (HTML) */
    public array $description = [];
    public ?int $issueId = null;
    public ?int $sectionId = null;
    /** Y-m-d */
    public ?string $deadline = null;
    public ?string $guidelinesUrl = null;
    public string $notes = '';
    public bool $showContributors = true;
    public ?int $reminderDays = null;
    public ?int $overdueInterval = null;
    public ?int $overdueMax = null;
    public bool $cancelled = false;
    public ?string $dateInvited = null;
    public ?int $createdBy = null;
    public ?string $dateCreated = null;
    public ?string $dateModified = null;
    /** @var array<int,string> userId => notify mode (see Notifier) */
    public array $editors = [];

    public function getLocalizedTitle(?string $locale = null): string
    {
        return self::localize($this->title, $locale);
    }

    public function getLocalizedDescription(?string $locale = null): string
    {
        return self::localize($this->description, $locale);
    }

    public function isEditor(?int $userId): bool
    {
        return $userId !== null && array_key_exists($userId, $this->editors);
    }

    /**
     * The value in the given (or current) locale, else in the first locale that has one.
     */
    public static function localize(array $values, ?string $locale = null): string
    {
        $locale ??= \PKP\facades\Locale::getLocale();
        if (($values[$locale] ?? '') !== '') {
            return $values[$locale];
        }
        foreach ($values as $value) {
            if ((string) $value !== '') {
                return $value;
            }
        }
        return '';
    }
}
