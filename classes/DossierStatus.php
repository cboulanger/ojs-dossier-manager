<?php

/**
 * @file plugins/generic/dossierManager/classes/DossierStatus.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierStatus
 *
 * @brief The status of a dossier, derived from its data and its contributions.
 */

namespace APP\plugins\generic\dossierManager\classes;

class DossierStatus
{
    const PLANNING = 'planning';
    const INVITED = 'invited';
    const COLLECTING = 'collecting';
    const COMPLETE = 'complete';
    const PUBLISHED = 'published';
    const CANCELLED = 'cancelled';

    /**
     * @param string[] $contributionStatuses Derived statuses (ContributionStatus)
     * @param string $today Y-m-d
     */
    public static function derive(bool $cancelled, bool $invited, bool $issuePublished, ?string $deadline,
            array $contributionStatuses, string $today): string
    {
        if ($cancelled) {
            return self::CANCELLED;
        }
        if ($issuePublished) {
            return self::PUBLISHED;
        }
        if (!$invited) {
            return self::PLANNING;
        }
        $active = array_filter($contributionStatuses,
            fn ($s) => !in_array($s, [ContributionStatus::DECLINED, ContributionStatus::DROPPED], true));
        if ($active && !array_filter($active, fn ($s) => !ContributionStatus::isSubmitted($s))) {
            return self::COMPLETE;
        }
        return $deadline && $today > substr($deadline, 0, 10) ? self::COLLECTING : self::INVITED;
    }
}
