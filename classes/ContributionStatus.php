<?php

/**
 * @file plugins/generic/dossierManager/classes/ContributionStatus.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContributionStatus
 *
 * @brief The status of a planned contribution. Only planned, invited, declined
 *  and dropped are stored; once a submission is attached, the status follows
 *  the submission.
 */

namespace APP\plugins\generic\dossierManager\classes;

class ContributionStatus
{
    const PLANNED = 'planned';
    const INVITED = 'invited';
    const DECLINED = 'declined';
    const DROPPED = 'dropped';
    const IN_PROGRESS = 'inProgress';
    const SUBMITTED = 'submitted';
    const NOT_ACCEPTED = 'notAccepted';
    const PUBLISHED = 'published';

    /** Statuses that are stored in dm_contributions.status */
    const STORED = [self::PLANNED, self::INVITED, self::DECLINED, self::DROPPED];

    /** OJS submission statuses (PKPSubmission::STATUS_*) */
    const SUBMISSION_PUBLISHED = 3;
    const SUBMISSION_DECLINED = 4;

    /**
     * @param string $stored The stored status
     * @param ?array $submission ['progress' => submissionProgress, 'status' => submission status], null without submission
     */
    public static function derive(string $stored, ?array $submission): string
    {
        if (in_array($stored, [self::DECLINED, self::DROPPED], true) || !$submission) {
            return $stored;
        }
        if ((string) $submission['progress'] !== '') {
            return self::IN_PROGRESS;
        }
        return match ((int) $submission['status']) {
            self::SUBMISSION_DECLINED => self::NOT_ACCEPTED,
            self::SUBMISSION_PUBLISHED => self::PUBLISHED,
            default => self::SUBMITTED,
        };
    }

    /**
     * Is the contributor still expected to submit (and to be reminded)?
     */
    public static function isOpen(string $status): bool
    {
        return in_array($status, [self::INVITED, self::IN_PROGRESS], true);
    }

    /**
     * Has the contribution been submitted (whatever happened afterwards)?
     */
    public static function isSubmitted(string $status): bool
    {
        return in_array($status, [self::SUBMITTED, self::NOT_ACCEPTED, self::PUBLISHED], true);
    }
}
