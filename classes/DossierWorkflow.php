<?php

/**
 * @file plugins/generic/dossierManager/classes/DossierWorkflow.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierWorkflow
 *
 * @brief The steps of a dossier that change data, log them and notify the
 *  responsible editors: inviting, declining, attaching submissions, moving
 *  them into the target issue and section, and changing deadlines.
 */

namespace APP\plugins\generic\dossierManager\classes;

use APP\facades\Repo;
use APP\plugins\generic\dossierManager\mailables\DmGuestInvitation;
use APP\plugins\generic\dossierManager\mailables\DmInvitation;
use APP\submission\Submission;
use PKP\core\Core;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\stageAssignment\StageAssignment;
use PKP\user\User;
use PKP\userGroup\relationships\UserUserGroup;
use PKP\userGroup\UserGroup;

class DossierWorkflow
{
    /**
     * The account of a new contributor: an existing account with this email, or a new one
     * (random password, must be changed), added to the journal's Author group. No email is sent.
     * @return array [User, bool created]
     */
    public static function findOrCreateContributor($context, string $givenName, string $familyName, string $email): array
    {
        return self::findOrCreateUser($context, $givenName, $familyName, $email, true);
    }

    /**
     * An existing account with this email, or a new one (random password, must be changed).
     * @param bool $asAuthor Add the user to the journal's Author group
     * @return array [User, bool created]
     */
    public static function findOrCreateUser($context, string $givenName, string $familyName, string $email, bool $asAuthor): array
    {
        $locale = $context->getPrimaryLocale();
        $user = Repo::user()->getByEmail($email, true);
        $created = false;
        if (!$user) {
            $user = Repo::user()->newDataObject();
            $user->setUsername(self::uniqueUsername($email));
            $user->setEmail($email);
            $user->setPassword(Validation::encryptCredentials($user->getUsername(), Validation::generatePassword()));
            $user->setGivenName($givenName, $locale);
            $user->setFamilyName($familyName, $locale);
            $user->setDateRegistered(Core::getCurrentDate());
            $user->setMustChangePassword(true);
            Repo::user()->add($user);
            $user = Repo::user()->get($user->getId(), true);
            $created = true;
        }
        if (!$asAuthor) {
            return [$user, $created];
        }
        $authorGroup = UserGroup::withContextIds([$context->getId()])->withRoleIds([Role::ROLE_ID_AUTHOR])->isDefault(true)->first();
        if ($authorGroup && !UserUserGroup::withUserId($user->getId())->withUserGroupIds([$authorGroup->userGroupId])->exists()) {
            UserUserGroup::create(['userId' => $user->getId(), 'userGroupId' => $authorGroup->userGroupId,
                'dateStart' => Core::getCurrentDate(), 'dateEnd' => null, 'masthead' => null]);
        }
        return [$user, $created];
    }

    protected static function uniqueUsername(string $email): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', strstr($email, '@', true) ?: 'contributor')) ?: 'contributor';
        $username = $base;
        for ($i = 2; Repo::user()->getByUsername($username, true); $i++) {
            $username = $base . $i;
        }
        return $username;
    }

    /**
     * Can the contribution be invited (again)? It needs a contributor and must not be declined, dropped or submitted.
     */
    public static function canInvite(Contribution $c): bool
    {
        return $c->userId && in_array(DossierRepository::contributionStatus($c), [ContributionStatus::PLANNED, ContributionStatus::INVITED, ContributionStatus::IN_PROGRESS], true);
    }

    /**
     * Why invitations can't be sent yet, or null. The email names the issue, section and deadline.
     */
    public static function invitationBlocker(Dossier $dossier): ?string
    {
        if (!$dossier->issueId || !$dossier->sectionId || !$dossier->deadline) {
            return 'plugins.generic.dossierManager.invite.missingTarget';
        }
        if ($dossier->cancelled) {
            return 'plugins.generic.dossierManager.invite.cancelled';
        }
        return null;
    }

    /**
     * Send the invitation (edited once) to contributors.
     * @param Contribution[] $contributions
     * @return int Number of invitations sent or recorded
     */
    public static function invite($plugin, $context, Dossier $dossier, array $contributions, string $subject, string $body, User $sender): int
    {
        $count = 0;
        foreach ($contributions as $c) {
            if (!self::canInvite($c)) {
                continue;
            }
            $user = Repo::user()->get($c->userId, true);
            if (!$user) {
                continue;
            }
            $c->linkKey ??= DossierRepository::newLinkKey();
            if ($c->status === ContributionStatus::PLANNED) {
                $c->status = ContributionStatus::INVITED;
            }
            $c->dateInvited = Core::getCurrentDate();
            DossierRepository::saveContribution($c);
            DossierMailer::send($context, DmInvitation::class, DossierLog::EMAIL_INVITATION, $dossier, $c, $user, $sender, $subject, $body);
            DossierLog::event($dossier->id, 'invited', $sender, $user->getFullName(), $c->workingTitle);
            $count++;
        }
        if ($count && !$dossier->dateInvited) {
            $dossier->dateInvited = Core::getCurrentDate();
            DossierRepository::saveDossier($dossier);
        }
        return $count;
    }

    /**
     * The contributor declines (through the link) or an editor records that they declined.
     * The submission link stops working.
     */
    public static function decline($plugin, $context, Dossier $dossier, Contribution $c, string $reason, ?User $actor, bool $viaLink): void
    {
        $c->status = ContributionStatus::DECLINED;
        $c->declineReason = $reason;
        DossierRepository::saveContribution($c);
        $contributor = $c->userId ? Repo::user()->get($c->userId, true) : null;
        $name = $contributor?->getFullName() ?? '';
        DossierLog::event($dossier->id, $viaLink ? 'declined' : 'declinedByEditor', $actor, $name, $c->workingTitle,
            $viaLink ? DossierLog::VIA_LINK : null);
        Notifier::notify($plugin, $context, $dossier, 'declined', [
            'name' => $name,
            'title' => $c->workingTitle ?: '–',
            'reason' => $reason !== '' ? $reason : '–',
        ], $viaLink ? null : $actor);
    }

    /**
     * Attach a (new or incomplete) submission to a contribution. Sets the target section if
     * authors may submit to it (otherwise the section is set on completion, see onSubmitted())
     * and proposes the working title as the title.
     */
    public static function attach($context, Dossier $dossier, Contribution $c, Submission $submission, User $user): void
    {
        $c->submissionId = $submission->getId();
        if ($c->status === ContributionStatus::PLANNED) {
            $c->status = ContributionStatus::INVITED;
        }
        DossierRepository::saveContribution($c);

        $publication = $submission->getCurrentPublication();
        $changes = [];
        if ($dossier->sectionId && $publication->getData('sectionId') != $dossier->sectionId) {
            $section = Repo::section()->get($dossier->sectionId, $context->getId());
            if ($section && !$section->getIsInactive() && !$section->getEditorRestricted()) {
                $changes['sectionId'] = $dossier->sectionId;
            }
        }
        if ($c->workingTitle !== '' && !$publication->getLocalizedData('title', $submission->getData('locale'))) {
            $changes['title'] = [$submission->getData('locale') => $c->workingTitle];
        }
        if ($changes) {
            Repo::publication()->edit($publication, $changes);
        }
        DossierLog::event($dossier->id, 'submissionStarted', $user, $user->getFullName(), $c->workingTitle);
    }

    /**
     * Detach a submission that has not been completed from its contribution.
     */
    public static function detach(Dossier $dossier, Contribution $c, User $user): void
    {
        $c->submissionId = null;
        DossierRepository::saveContribution($c);
        DossierLog::event($dossier->id, 'detached', $user, $user->getFullName(), $c->workingTitle);
    }

    /**
     * The submission of a contribution was completed: move it into the target section and issue,
     * log it and notify the editors.
     */
    public static function onSubmitted($plugin, $context, Dossier $dossier, Contribution $c, Submission $submission): void
    {
        self::moveToTarget($context, $dossier, $submission);
        $contributor = $c->userId ? Repo::user()->get($c->userId, true) : null;
        $name = $contributor?->getFullName() ?? '';
        DossierLog::event($dossier->id, 'submitted', $contributor, $name, $c->workingTitle);
        self::assignEditors($plugin, $context, $dossier, $c, $submission->getId(), null);
        Notifier::notify($plugin, $context, $dossier, 'submitted', [
            'name' => $name,
            'title' => (string) $submission->getCurrentPublication()->getLocalizedFullTitle(null, 'html') ?: $c->workingTitle,
        ]);
    }

    /**
     * Assign the dossier's responsible editors to a submitted contribution, so that they can
     * open it in the editorial workflow. OJS's own assignment of the section's editors still
     * happens (it runs after this, on SubmissionSubmitted). Existing assignments are kept;
     * editors who are no longer responsible are not removed from the submission.
     * @return string[] Names of the editors assigned now
     */
    public static function assignEditors($plugin, $context, Dossier $dossier, Contribution $c, int $submissionId, ?User $actor): array
    {
        $dossierEditorGroupId = (int) $plugin->getSetting($context->getId(), Roles::SETTING_GROUP_ID);
        $assigned = [];
        foreach (array_keys($dossier->editors) as $userId) {
            $editor = Repo::user()->get($userId, true);
            if (!$editor || $editor->getDisabled()) {
                continue;
            }
            $groups = UserGroup::withContextIds([$context->getId()])
                ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
                ->whereHas('userUserGroups', fn ($query) => $query->withUserId($userId)->withActive())
                ->get();
            if ($groups->isEmpty()) {
                continue;
            }
            // The Dossier editor group if the user has it, else their (sub)editor or manager group
            $group = $groups->first(fn ($g) => (int) $g->getKey() === $dossierEditorGroupId)
                ?? $groups->first(fn ($g) => (int) $g->roleId === Role::ROLE_ID_SUB_EDITOR)
                ?? $groups->first();
            $alreadyAssigned = StageAssignment::withSubmissionIds([$submissionId])->withUserId($userId)->exists();
            Repo::stageAssignment()->build($submissionId, (int) $group->getKey(), $userId, (bool) $group->recommendOnly);
            if (!$alreadyAssigned) {
                $assigned[] = $editor->getFullName();
            }
        }
        if ($assigned) {
            DossierLog::event($dossier->id, 'editorsAssigned', $actor, implode(', ', $assigned), $c->workingTitle,
                $actor ? null : DossierLog::AUTOMATIC);
        }
        return $assigned;
    }

    /**
     * Assign the responsible editors to all submitted contributions of a dossier (after the editors changed).
     * @return int Number of new assignments
     */
    public static function assignEditorsToSubmitted($plugin, $context, Dossier $dossier, ?User $actor): int
    {
        $count = 0;
        foreach (DossierRepository::getContributions($dossier->id) as $c) {
            $info = DossierRepository::submissionInfo($c->submissionId);
            if ($info && $info['progress'] === '') {
                $count += count(self::assignEditors($plugin, $context, $dossier, $c, $info['id'], $actor));
            }
        }
        return $count;
    }

    /**
     * Put the current publication of a submission into the dossier's target section and issue,
     * unless it has been scheduled or published. Publishing still needs the editors' "Schedule for publication".
     * @return bool Whether something changed
     */
    public static function moveToTarget($context, Dossier $dossier, Submission $submission): bool
    {
        $publication = $submission->getCurrentPublication();
        if (!$publication || (int) $publication->getData('status') !== Submission::STATUS_QUEUED) {
            return false;
        }
        $changes = [];
        if ($dossier->sectionId && (int) $publication->getData('sectionId') !== $dossier->sectionId
                && Repo::section()->get($dossier->sectionId, $context->getId())) {
            $changes['sectionId'] = $dossier->sectionId;
        }
        if ($dossier->issueId && (int) $publication->getData('issueId') !== $dossier->issueId
                && ($issue = Repo::issue()->get($dossier->issueId)) && !$issue->getPublished()) {
            $changes['issueId'] = $dossier->issueId;
        }
        if ($changes) {
            Repo::publication()->edit($publication, $changes);
        }
        return (bool) $changes;
    }

    /**
     * The dossier's target issue or section changed: move the submitted contributions that
     * are not scheduled or published yet.
     * @return int Number of submissions moved
     */
    public static function moveSubmittedToTarget($context, Dossier $dossier, ?User $actor): int
    {
        $moved = 0;
        foreach (DossierRepository::getContributions($dossier->id) as $c) {
            $info = DossierRepository::submissionInfo($c->submissionId);
            if (!$info || $info['progress'] !== '') {
                continue;
            }
            $submission = Repo::submission()->get($c->submissionId);
            if ($submission && self::moveToTarget($context, $dossier, $submission)) {
                $moved++;
                DossierLog::event($dossier->id, 'moved', $actor, null, $c->workingTitle);
            }
        }
        return $moved;
    }

    /**
     * A deadline changed: reminders start again for the new deadline.
     * @param ?Contribution $only Only this contribution (its individual deadline changed)
     */
    public static function resetReminders(Dossier $dossier, ?Contribution $only = null): void
    {
        foreach ($only ? [$only] : DossierRepository::getContributions($dossier->id) as $c) {
            // Contributions with their own deadline are not affected by the dossier's
            if (!$only && $c->deadline) {
                continue;
            }
            $c->reminderSent = null;
            $c->overdueCount = 0;
            $c->lastOverdue = null;
            DossierRepository::saveContribution($c);
        }
    }

    /**
     * Tell a guest editor about the dossier, with a link to its status page.
     * People who have never logged in are told how to set a password.
     * @return bool Whether it was sent (false: emails are switched off)
     */
    public static function inviteGuestEditor($plugin, $context, Dossier $dossier, User $guest, User $sender): bool
    {
        $locale = $context->getPrimaryLocale();
        $accountHint = '';
        if (!$guest->getDateLastLogin()) {
            $accountHint = '<p>' . htmlspecialchars(__('plugins.generic.dossierManager.email.guestAccountHint',
                ['email' => $guest->getEmail()], $locale))
                . '<br /><a href="' . DossierMailer::pageUrl($context, 'login', 'lostPassword') . '">'
                . htmlspecialchars(__('plugins.generic.dossierManager.email.setPassword', [], $locale)) . '</a></p>';
        }
        // The dossier may still be in planning
        $params = DossierMailer::getParams($context, $dossier, $sender);
        $notYet = htmlspecialchars(__('plugins.generic.dossierManager.form.notYet', [], $locale));
        $extra = ['accountHint' => $accountHint];
        foreach (['issueTitle', 'dossierDeadline', 'contributionsList'] as $name) {
            if ($params[$name] === '') {
                $extra[$name] = $name === 'contributionsList' ? '<p>' . $notYet . '</p>' : $notYet;
            }
        }
        DossierMailer::ensureTemplates($plugin, $context->getId());
        [$subject, $body] = DossierMailer::compose($context, DmGuestInvitation::class, $dossier, $sender, $extra);
        return DossierMailer::send($context, DmGuestInvitation::class, DossierLog::EMAIL_GUEST_INVITATION, $dossier, null, $guest, $sender, $subject, $body);
    }
}
