<?php

/**
 * @file plugins/generic/dossierManager/classes/DossierRepository.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierRepository
 *
 * @brief Storage of dossiers, their responsible editors and contributions,
 *  and the statuses derived from them.
 */

namespace APP\plugins\generic\dossierManager\classes;

use Illuminate\Support\Facades\DB;
use PKP\core\Core;

class DossierRepository
{
    /* ---------- Dossiers ---------- */

    public static function getDossier(int $dossierId, ?int $contextId = null): ?Dossier
    {
        $row = DB::table('dm_dossiers')->where('dossier_id', $dossierId)
            ->when($contextId !== null, fn ($q) => $q->where('context_id', $contextId))
            ->first();
        return $row ? self::fromDossierRow($row) : null;
    }

    /**
     * Dossiers of a journal, optionally only those a user is responsible for.
     * Open dossiers first (by deadline), cancelled ones last.
     * @return Dossier[]
     */
    public static function getDossiers(int $contextId, ?int $editorUserId = null): array
    {
        $rows = DB::table('dm_dossiers')->where('context_id', $contextId)
            ->when($editorUserId !== null, fn ($q) => $q->whereIn('dossier_id',
                fn ($sub) => $sub->select('dossier_id')->from('dm_dossier_editors')->where('user_id', $editorUserId)))
            ->orderBy('cancelled')
            ->orderByRaw('deadline IS NULL')
            ->orderBy('deadline')
            ->orderBy('dossier_id')
            ->get();
        $editors = self::editorsByDossier($rows->pluck('dossier_id')->all());
        return $rows->map(fn ($row) => self::fromDossierRow($row, $editors[$row->dossier_id] ?? []))->all();
    }

    /**
     * Insert or update a dossier, including its responsible editors.
     * @return int The dossier ID
     */
    public static function saveDossier(Dossier $dossier): int
    {
        $now = Core::getCurrentDate();
        $values = [
            'context_id' => $dossier->contextId,
            'type' => $dossier->type,
            'title' => json_encode($dossier->title, JSON_UNESCAPED_UNICODE),
            'description' => json_encode($dossier->description, JSON_UNESCAPED_UNICODE),
            'issue_id' => $dossier->issueId,
            'section_id' => $dossier->sectionId,
            'deadline' => $dossier->deadline,
            'guidelines_url' => $dossier->guidelinesUrl,
            'notes' => $dossier->notes,
            'show_contributors' => $dossier->showContributors,
            'reminder_days' => $dossier->reminderDays,
            'overdue_interval' => $dossier->overdueInterval,
            'overdue_max' => $dossier->overdueMax,
            'cancelled' => $dossier->cancelled,
            'date_invited' => $dossier->dateInvited,
            'created_by' => $dossier->createdBy,
            'date_modified' => $now,
        ];
        if ($dossier->id) {
            DB::table('dm_dossiers')->where('dossier_id', $dossier->id)->update($values);
        } else {
            $dossier->dateCreated = $now;
            $dossier->id = (int) DB::table('dm_dossiers')->insertGetId($values + ['date_created' => $now], 'dossier_id');
        }
        $dossier->dateModified = $now;

        // Keep the notification preference of editors who stay
        $current = DB::table('dm_dossier_editors')->where('dossier_id', $dossier->id)->pluck('notify_mode', 'user_id')->all();
        DB::table('dm_dossier_editors')->where('dossier_id', $dossier->id)
            ->whereNotIn('user_id', array_keys($dossier->editors))->delete();
        foreach ($dossier->editors as $userId => $mode) {
            if (!array_key_exists($userId, $current)) {
                DB::table('dm_dossier_editors')->insert(['dossier_id' => $dossier->id, 'user_id' => $userId,
                    'notify_mode' => $mode ?: Notifier::MODE_DEFAULT]);
            }
        }
        $dossier->editors = DB::table('dm_dossier_editors')->where('dossier_id', $dossier->id)->pluck('notify_mode', 'user_id')->all();
        return $dossier->id;
    }

    public static function setNotifyMode(int $dossierId, int $userId, string $mode): void
    {
        DB::table('dm_dossier_editors')->where('dossier_id', $dossierId)->where('user_id', $userId)->update(['notify_mode' => $mode]);
    }

    /* ---------- Contributions ---------- */

    public static function getContribution(int $contributionId): ?Contribution
    {
        $row = DB::table('dm_contributions')->where('contribution_id', $contributionId)->first();
        return $row ? self::fromContributionRow($row) : null;
    }

    /** @return Contribution[] In their planned order */
    public static function getContributions(int $dossierId): array
    {
        return DB::table('dm_contributions')->where('dossier_id', $dossierId)
            ->orderBy('seq')->orderBy('contribution_id')->get()
            ->map(fn ($row) => self::fromContributionRow($row))->all();
    }

    public static function getContributionByKey(string $key): ?Contribution
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
            return null;
        }
        $row = DB::table('dm_contributions')->where('link_key', $key)->first();
        return $row ? self::fromContributionRow($row) : null;
    }

    public static function getContributionBySubmission(int $submissionId): ?Contribution
    {
        $row = DB::table('dm_contributions')->where('submission_id', $submissionId)->first();
        return $row ? self::fromContributionRow($row) : null;
    }

    /**
     * A user's contributions in a journal's dossiers that are not cancelled.
     * @return Contribution[]
     */
    public static function getContributionsByUser(int $contextId, int $userId): array
    {
        return DB::table('dm_contributions as c')
            ->join('dm_dossiers as d', 'd.dossier_id', '=', 'c.dossier_id')
            ->where('d.context_id', $contextId)
            ->where('d.cancelled', false)
            ->where('c.user_id', $userId)
            ->orderBy('d.deadline')->orderBy('c.seq')
            ->select('c.*')->get()
            ->map(fn ($row) => self::fromContributionRow($row))->all();
    }

    /**
     * @return int The contribution ID
     */
    public static function saveContribution(Contribution $c): int
    {
        $values = [
            'dossier_id' => $c->dossierId,
            'seq' => $c->seq,
            'working_title' => $c->workingTitle,
            'user_id' => $c->userId,
            'deadline' => $c->deadline,
            'note' => $c->note,
            'status' => $c->status,
            'link_key' => $c->linkKey,
            'submission_id' => $c->submissionId,
            'date_invited' => $c->dateInvited,
            'reminder_sent' => $c->reminderSent,
            'overdue_count' => $c->overdueCount,
            'last_overdue' => $c->lastOverdue,
            'reminders_paused' => $c->remindersPaused,
            'decline_reason' => $c->declineReason,
        ];
        if ($c->id) {
            DB::table('dm_contributions')->where('contribution_id', $c->id)->update($values);
        } else {
            $c->dateCreated = Core::getCurrentDate();
            if (!$c->seq) {
                $c->seq = $values['seq'] = (int) DB::table('dm_contributions')->where('dossier_id', $c->dossierId)->max('seq') + 1;
            }
            $c->id = (int) DB::table('dm_contributions')->insertGetId($values + ['date_created' => $c->dateCreated], 'contribution_id');
        }
        return $c->id;
    }

    public static function deleteContribution(int $contributionId): void
    {
        DB::table('dm_contributions')->where('contribution_id', $contributionId)->delete();
    }

    /**
     * Move a contribution one place up (-1) or down (+1) and renumber the list.
     */
    public static function moveContribution(Contribution $c, int $direction): void
    {
        $ids = array_map(fn ($x) => $x->id, self::getContributions($c->dossierId));
        $pos = array_search($c->id, $ids, true);
        $target = $pos + $direction;
        if ($pos === false || $target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
        foreach ($ids as $i => $id) {
            DB::table('dm_contributions')->where('contribution_id', $id)->update(['seq' => $i + 1]);
        }
    }

    /** A new key for a submission link (32 hex characters) */
    public static function newLinkKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /* ---------- Derived statuses ---------- */

    /**
     * The attached submission's progress, status and stage, or null.
     * @return ?array [id, progress, status, stageId, publicationId, publicationStatus, issueId, sectionId]
     */
    public static function submissionInfo(?int $submissionId): ?array
    {
        if (!$submissionId) {
            return null;
        }
        $row = DB::table('submissions as s')
            ->leftJoin('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->where('s.submission_id', $submissionId)
            ->select('s.submission_id', 's.submission_progress', 's.status', 's.stage_id',
                'p.publication_id', 'p.status as publication_status', 'p.issue_id', 'p.section_id')
            ->first();
        return $row ? [
            'id' => (int) $row->submission_id,
            'progress' => (string) $row->submission_progress,
            'status' => (int) $row->status,
            'stageId' => (int) $row->stage_id,
            'publicationId' => $row->publication_id ? (int) $row->publication_id : null,
            'publicationStatus' => (int) $row->publication_status,
            'issueId' => $row->issue_id ? (int) $row->issue_id : null,
            'sectionId' => $row->section_id ? (int) $row->section_id : null,
        ] : null;
    }

    public static function contributionStatus(Contribution $c): string
    {
        return ContributionStatus::derive($c->status, self::submissionInfo($c->submissionId));
    }

    /**
     * @param ?Contribution[] $contributions null: load them
     */
    public static function dossierStatus(Dossier $dossier, ?array $contributions = null): string
    {
        $contributions ??= self::getContributions($dossier->id);
        $issuePublished = $dossier->issueId
            && (bool) DB::table('issues')->where('issue_id', $dossier->issueId)->value('published');
        return DossierStatus::derive($dossier->cancelled, (bool) $dossier->dateInvited, $issuePublished, $dossier->deadline,
            array_map(fn ($c) => self::contributionStatus($c), $contributions), date('Y-m-d'));
    }

    /* ---------- Mapping ---------- */

    protected static function fromDossierRow(object $row, ?array $editors = null): Dossier
    {
        $d = new Dossier();
        $d->id = (int) $row->dossier_id;
        $d->contextId = (int) $row->context_id;
        $d->type = (string) $row->type;
        $d->title = (array) (json_decode((string) $row->title, true) ?: []);
        $d->description = (array) (json_decode((string) $row->description, true) ?: []);
        $d->issueId = $row->issue_id ? (int) $row->issue_id : null;
        $d->sectionId = $row->section_id ? (int) $row->section_id : null;
        $d->deadline = $row->deadline ? substr((string) $row->deadline, 0, 10) : null;
        $d->guidelinesUrl = $row->guidelines_url ?: null;
        $d->notes = (string) $row->notes;
        $d->showContributors = (bool) $row->show_contributors;
        $d->reminderDays = $row->reminder_days !== null ? (int) $row->reminder_days : null;
        $d->overdueInterval = $row->overdue_interval !== null ? (int) $row->overdue_interval : null;
        $d->overdueMax = $row->overdue_max !== null ? (int) $row->overdue_max : null;
        $d->cancelled = (bool) $row->cancelled;
        $d->dateInvited = $row->date_invited;
        $d->createdBy = $row->created_by ? (int) $row->created_by : null;
        $d->dateCreated = $row->date_created;
        $d->dateModified = $row->date_modified;
        $d->editors = $editors ?? (self::editorsByDossier([$d->id])[$d->id] ?? []);
        return $d;
    }

    /** @return array<int,array<int,string>> dossierId => [userId => notify mode] */
    protected static function editorsByDossier(array $dossierIds): array
    {
        $editors = [];
        if ($dossierIds) {
            foreach (DB::table('dm_dossier_editors')->whereIn('dossier_id', $dossierIds)->get() as $row) {
                $editors[(int) $row->dossier_id][(int) $row->user_id] = (string) $row->notify_mode;
            }
        }
        return $editors;
    }

    protected static function fromContributionRow(object $row): Contribution
    {
        $c = new Contribution();
        $c->id = (int) $row->contribution_id;
        $c->dossierId = (int) $row->dossier_id;
        $c->seq = (int) $row->seq;
        $c->workingTitle = (string) $row->working_title;
        $c->userId = $row->user_id ? (int) $row->user_id : null;
        $c->deadline = $row->deadline ? substr((string) $row->deadline, 0, 10) : null;
        $c->note = (string) $row->note;
        $c->status = (string) $row->status;
        $c->linkKey = $row->link_key ?: null;
        $c->submissionId = $row->submission_id ? (int) $row->submission_id : null;
        $c->dateInvited = $row->date_invited;
        $c->reminderSent = $row->reminder_sent;
        $c->overdueCount = (int) $row->overdue_count;
        $c->lastOverdue = $row->last_overdue;
        $c->remindersPaused = (bool) $row->reminders_paused;
        $c->declineReason = (string) $row->decline_reason;
        $c->dateCreated = $row->date_created;
        return $c;
    }
}
