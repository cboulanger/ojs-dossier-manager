<?php

/**
 * @file plugins/generic/dossierManager/classes/DossierReminder.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierReminder
 *
 * @brief Daily task:
 *  - remind contributors before the deadline, and after it at an interval (ReminderPolicy);
 *  - notify the responsible editors when the maximum number of overdue reminders is reached;
 *  - send the editors' daily digests.
 *  With the plugin's emails switched off, nothing happens.
 */

namespace APP\plugins\generic\dossierManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\dossierManager\mailables\DmOverdue;
use APP\plugins\generic\dossierManager\mailables\DmReminder;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;

class DossierReminder extends ScheduledTask
{
    public function getName(): string
    {
        return __('plugins.generic.dossierManager.reminder.taskName');
    }

    protected function executeActions(): bool
    {
        $plugin = PluginRegistry::getPlugin('generic', 'DossierManagerPlugin');
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
        $contexts = Application::getContextDAO()->getAll(true);
        while ($context = $contexts->next()) {
            if (!$pluginSettingsDao->getSetting($context->getId(), Settings::PLUGIN_NAME, 'enabled')
                    || Settings::emailsSuppressed($context->getId())) {
                continue;
            }
            $counts = $this->sendReminders($plugin, $context, date('Y-m-d'));
            $counts['digests'] = Notifier::sendDigests($plugin, $context);
            if (array_sum($counts)) {
                $this->addExecutionLogEntry(__('plugins.generic.dossierManager.reminder.sent',
                    $counts + ['context' => $context->getLocalizedName()]));
            }
        }
        return true;
    }

    /**
     * @return array [before, overdue] Numbers of reminders sent
     */
    public function sendReminders($plugin, $context, string $today): array
    {
        $counts = ['before' => 0, 'overdue' => 0];
        foreach (DossierRepository::getDossiers($context->getId()) as $dossier) {
            if ($dossier->cancelled || !$dossier->dateInvited) {
                continue;
            }
            [$daysBefore, $interval, $max] = Settings::reminderSettings($dossier);
            foreach (DossierRepository::getContributions($dossier->id) as $c) {
                if ($c->remindersPaused || !$c->userId || !ContributionStatus::isOpen(DossierRepository::contributionStatus($c))) {
                    continue;
                }
                $due = ReminderPolicy::due([
                    'deadline' => $c->getEffectiveDeadline($dossier),
                    'dateInvited' => $c->dateInvited,
                    'reminderSent' => $c->reminderSent,
                    'overdueCount' => $c->overdueCount,
                    'lastOverdue' => $c->lastOverdue,
                ], $today, $daysBefore, $interval, $max);
                if (!$due) {
                    continue;
                }
                $user = Repo::user()->get($c->userId, true);
                if (!$user || $user->getDisabled()) {
                    continue;
                }
                $c->linkKey ??= DossierRepository::newLinkKey();
                $inProgress = $c->submissionId ? __('plugins.generic.dossierManager.email.inProgress', [], $context->getPrimaryLocale()) : '';
                if ($due === ReminderPolicy::BEFORE) {
                    DossierMailer::sendTemplate($plugin, $context, DmReminder::class, DossierLog::EMAIL_REMINDER, $dossier, $c, $user,
                        ['inProgressNote' => $inProgress]);
                    $c->reminderSent = Core::getCurrentDate();
                    DossierRepository::saveContribution($c);
                    DossierLog::event($dossier->id, 'reminderSent', null, $user->getFullName(), $c->workingTitle, DossierLog::AUTOMATIC);
                    $counts['before']++;
                    continue;
                }
                $c->overdueCount++;
                $c->lastOverdue = Core::getCurrentDate();
                DossierMailer::sendTemplate($plugin, $context, DmOverdue::class, DossierLog::EMAIL_OVERDUE, $dossier, $c, $user,
                    ['inProgressNote' => $inProgress, 'overdueCount' => (string) $c->overdueCount]);
                DossierRepository::saveContribution($c);
                DossierLog::event($dossier->id, 'overdueSent', null, $user->getFullName(), $c->workingTitle, DossierLog::AUTOMATIC);
                $counts['overdue']++;
                if ($max > 0 && $c->overdueCount >= $max) {
                    DossierLog::event($dossier->id, 'maxReminders', null, $user->getFullName(), $c->workingTitle, DossierLog::AUTOMATIC);
                    Notifier::notify($plugin, $context, $dossier, 'maxReminders', [
                        'name' => $user->getFullName(),
                        'title' => $c->workingTitle ?: '–',
                        'number' => $c->overdueCount,
                    ]);
                }
            }
        }
        return $counts;
    }
}
