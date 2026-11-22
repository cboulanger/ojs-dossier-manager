<?php

/**
 * @file plugins/generic/dossierManager/classes/Contribution.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Contribution
 *
 * @brief A planned contribution of a dossier: a working title and its contributor.
 */

namespace APP\plugins\generic\dossierManager\classes;

class Contribution
{
    public ?int $id = null;
    public int $dossierId = 0;
    public int $seq = 0;
    public string $workingTitle = '';
    public ?int $userId = null;
    /** Individual deadline (Y-m-d), overrides the dossier's */
    public ?string $deadline = null;
    public string $note = '';
    /** Stored status, see ContributionStatus::STORED */
    public string $status = ContributionStatus::PLANNED;
    public ?string $linkKey = null;
    public ?int $submissionId = null;
    public ?string $dateInvited = null;
    public ?string $reminderSent = null;
    public int $overdueCount = 0;
    public ?string $lastOverdue = null;
    public bool $remindersPaused = false;
    public string $declineReason = '';
    public ?string $dateCreated = null;

    public function getEffectiveDeadline(Dossier $dossier): ?string
    {
        return $this->deadline ?: $dossier->deadline;
    }
}
