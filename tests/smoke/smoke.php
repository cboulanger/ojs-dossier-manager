<?php

/**
 * @file plugins/generic/dossierManager/tests/smoke/smoke.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * End-to-end smoke test in the dev container (tests/run.sh smoke): storage,
 * invitations, reminders, notifications, digests and the history, against the
 * real OJS database. Creates its own dossier and deletes it afterwards.
 */

require '/var/www/html/tools/bootstrap.php';

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\dossierManager\classes\Contribution;
use APP\plugins\generic\dossierManager\classes\ContributionStatus;
use APP\plugins\generic\dossierManager\classes\Dossier;
use APP\plugins\generic\dossierManager\classes\DossierLog;
use APP\plugins\generic\dossierManager\classes\DossierMailer;
use APP\plugins\generic\dossierManager\classes\DossierReminder;
use APP\plugins\generic\dossierManager\classes\DossierRepository;
use APP\plugins\generic\dossierManager\classes\DossierStatus;
use APP\plugins\generic\dossierManager\classes\DossierWorkflow;
use APP\plugins\generic\dossierManager\classes\Notifier;
use APP\plugins\generic\dossierManager\mailables\DmInvitation;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginRegistry;

class DossierSmokeTool extends \PKP\cliTool\CommandLineTool
{
    private int $failures = 0;

    private function check(bool $ok, string $label): void
    {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
        $this->failures += $ok ? 0 : 1;
    }

    public function execute()
    {
        $context = Application::getContextDAO()->getByPath('test');
        PluginRegistry::loadCategory('generic', true, $context->getId());
        $plugin = PluginRegistry::getPlugin('generic', 'DossierManagerPlugin');
        $editor = Repo::user()->getByUsername('dossiereditor', true);
        $author = Repo::user()->getByUsername('author', true);
        $issue = Repo::issue()->getCollector()->filterByContextIds([$context->getId()])->filterByPublished(false)->getMany()->first();
        $section = Repo::section()->getCollector()->filterByContextIds([$context->getId()])->getMany()->first();
        $this->check($editor && $author && $issue && $section, 'dev data present (dossiereditor, author, future issue, section)');

        $dossier = new Dossier();
        $dossier->contextId = $context->getId();
        $dossier->type = 'Special issue';
        $dossier->title = ['en' => 'Smoke test dossier ' . uniqid()];
        $dossier->description = ['en' => '<p>About something.</p>'];
        $dossier->issueId = $issue->getId();
        $dossier->sectionId = $section->getId();
        $dossier->deadline = date('Y-m-d', strtotime('-1 day'));
        $dossier->editors = [$editor->getId() => Notifier::MODE_DEFAULT];
        DossierRepository::saveDossier($dossier);
        try {
            $this->check(DossierRepository::getDossier($dossier->id)?->getLocalizedTitle('en') === $dossier->title['en'], 'dossier stored and read back');

            $c = new Contribution();
            $c->dossierId = $dossier->id;
            $c->workingTitle = 'A planned article';
            $c->userId = $author->getId();
            DossierRepository::saveContribution($c);
            $this->check(DossierRepository::contributionStatus($c) === ContributionStatus::PLANNED, 'new contribution is planned');
            $this->check(DossierWorkflow::invitationBlocker($dossier) === null, 'dossier can be invited');

            [$subject, $body] = DossierMailer::compose($context, DmInvitation::class, $dossier, $editor);
            $this->check(str_contains($body, '{$submissionUrl}') && str_contains($body, 'A planned article'), 'invitation keeps late variables and lists contributions');
            $sent = DossierWorkflow::invite($plugin, $context, $dossier, [$c], $subject, $body, $editor);
            $c = DossierRepository::getContribution($c->id);
            $this->check($sent === 1 && $c->status === ContributionStatus::INVITED && $c->linkKey, 'invitation sent, link created');
            $this->check(DossierRepository::dossierStatus(DossierRepository::getDossier($dossier->id)) === DossierStatus::COLLECTING, 'dossier past its deadline is collecting');

            // Invited two days ago, deadline yesterday: an overdue reminder is due today
            DB::table('dm_contributions')->where('contribution_id', $c->id)->update(['date_invited' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
            $counts = (new DossierReminder())->sendReminders($plugin, $context, date('Y-m-d'));
            $c = DossierRepository::getContribution($c->id);
            $this->check($counts['overdue'] >= 1 && $c->overdueCount === 1, 'overdue reminder sent');
            $counts = (new DossierReminder())->sendReminders($plugin, $context, date('Y-m-d'));
            $this->check(DossierRepository::getContribution($c->id)->overdueCount === 1, 'no second reminder on the same day');

            // Paused: nothing
            $c->remindersPaused = true;
            $c->lastOverdue = date('Y-m-d H:i:s', strtotime('-30 days'));
            DossierRepository::saveContribution($c);
            (new DossierReminder())->sendReminders($plugin, $context, date('Y-m-d'));
            $this->check(DossierRepository::getContribution($c->id)->overdueCount === 1, 'paused contribution gets no reminder');

            // Deadline extension resets the counters
            $c->remindersPaused = false;
            $c->deadline = date('Y-m-d', strtotime('+30 days'));
            DossierRepository::saveContribution($c);
            DossierWorkflow::resetReminders(DossierRepository::getDossier($dossier->id), $c);
            $c = DossierRepository::getContribution($c->id);
            $this->check($c->overdueCount === 0 && !$c->lastOverdue, 'individual deadline resets reminders');

            // Decline: editors notified immediately (default mode)
            $before = DB::table('email_log')->where('assoc_type', DossierLog::ASSOC_TYPE)->where('assoc_id', $dossier->id)->count();
            DossierWorkflow::decline($plugin, $context, DossierRepository::getDossier($dossier->id), $c, 'No time', $author, true);
            $after = DB::table('email_log')->where('assoc_type', DossierLog::ASSOC_TYPE)->where('assoc_id', $dossier->id)->count();
            $this->check(DossierRepository::contributionStatus(DossierRepository::getContribution($c->id)) === ContributionStatus::DECLINED, 'contribution declined');
            $this->check($after === $before + 1, 'editor notified of the decline');

            // A change by someone else goes to the digest
            Notifier::notify($plugin, $context, DossierRepository::getDossier($dossier->id), 'edited', [], $author);
            $this->check(DB::table('dm_digest')->where('dossier_id', $dossier->id)->count() === 1, 'other change queued for the digest');
            $this->check(Notifier::sendDigests($plugin, $context) >= 1 && !DB::table('dm_digest')->where('dossier_id', $dossier->id)->exists(), 'digest sent and emptied');

            $history = DossierLog::getHistory($dossier->id);
            $this->check(count($history) >= 6 && !str_contains(json_encode($history), 'history.event'), 'history has translated entries');
        } finally {
            DB::table('dm_digest')->where('dossier_id', $dossier->id)->delete();
            DB::table('dm_contributions')->where('dossier_id', $dossier->id)->delete();
            DB::table('dm_dossier_editors')->where('dossier_id', $dossier->id)->delete();
            DB::table('dm_dossiers')->where('dossier_id', $dossier->id)->delete();
            DB::table('event_log')->where('assoc_type', DossierLog::ASSOC_TYPE)->where('assoc_id', $dossier->id)->delete();
            DB::table('email_log')->where('assoc_type', DossierLog::ASSOC_TYPE)->where('assoc_id', $dossier->id)->delete();
        }
        echo $this->failures ? "{$this->failures} check(s) failed\n" : "All checks passed\n";
        exit($this->failures ? 1 : 0);
    }
}

(new DossierSmokeTool($argv))->execute();
