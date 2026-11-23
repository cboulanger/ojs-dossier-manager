<?php

/**
 * @file plugins/generic/dossierManager/classes/DossierMailer.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierMailer
 *
 * @brief Emails about a dossier, based on the editable email templates and
 *  recorded in the dossier's history.
 *
 *  Values that differ per recipient (LATE_VARIABLES) stay as {$variable}
 *  placeholders in the text an editor edits, and are filled in by send().
 */

namespace APP\plugins\generic\dossierManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\dossierManager\mailables\DmEditorDigest;
use APP\plugins\generic\dossierManager\mailables\DmEditorNotification;
use APP\plugins\generic\dossierManager\mailables\DmGuestInvitation;
use APP\plugins\generic\dossierManager\mailables\DmInvitation;
use APP\plugins\generic\dossierManager\mailables\DmOverdue;
use APP\plugins\generic\dossierManager\mailables\DmReminder;
use APP\plugins\generic\dossierManager\pages\DossierHandler;
use APP\plugins\generic\dossierManager\pages\DossiersHandler;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPString;
use PKP\user\User;

class DossierMailer
{
    const MAILABLES = [DmInvitation::class, DmReminder::class, DmOverdue::class, DmEditorNotification::class, DmEditorDigest::class,
        DmGuestInvitation::class];

    /** Filled in per recipient by send() */
    const LATE_VARIABLES = ['recipientName', 'workingTitle', 'deadline', 'submissionUrl', 'declineUrl', 'declineLink'];

    /**
     * Install email templates that are missing (installation only adds them once).
     */
    public static function ensureTemplates($plugin, int $contextId): void
    {
        foreach (self::MAILABLES as $mailableClass) {
            if (!Repo::emailTemplate()->dao->getByKey($contextId, $mailableClass::getEmailTemplateKey())) {
                $locales = Application::get()->getRequest()->getSite()->getInstalledLocales();
                Repo::emailTemplate()->dao->installEmailTemplates($plugin->getInstallEmailTemplatesFile(), $locales, null, true);
                return;
            }
        }
    }

    public static function formatDate($context, ?string $date, ?string $locale = null): string
    {
        if (!$date) {
            return '';
        }
        $format = PKPString::convertStrftimeFormat($context->getLocalizedDateFormatLong($locale ?? $context->getPrimaryLocale()));
        return date($format, strtotime($date));
    }

    /**
     * Values for the template variables that are the same for all recipients.
     */
    public static function getParams($context, Dossier $dossier, ?User $sender, array $extra = []): array
    {
        $locale = $context->getPrimaryLocale();
        $issue = $dossier->issueId ? Repo::issue()->get($dossier->issueId) : null;
        $section = $dossier->sectionId ? Repo::section()->get($dossier->sectionId, $context->getId()) : null;
        return array_merge([
            'contextName' => htmlspecialchars($context->getLocalizedName($locale)),
            'senderName' => htmlspecialchars($sender ? $sender->getFullName() : (string) $context->getData('contactName')),
            'dossierType' => htmlspecialchars($dossier->type),
            'dossierTitle' => htmlspecialchars($dossier->getLocalizedTitle($locale)),
            'dossierDescription' => PKPString::stripUnsafeHtml($dossier->getLocalizedDescription($locale)),
            'issueTitle' => htmlspecialchars($issue ? $issue->getIssueIdentification([], $locale) : ''),
            'sectionTitle' => htmlspecialchars($section ? (string) $section->getLocalizedTitle() : ''),
            'dossierDeadline' => self::formatDate($context, $dossier->deadline, $locale),
            'contributionsList' => self::contributionsList($dossier),
            'guidelinesUrl' => htmlspecialchars(self::guidelinesUrl($context, $dossier)),
            'dossierUrl' => self::pageUrl($context, DossiersHandler::PAGE, 'view', [$dossier->id]),
            'editorNames' => htmlspecialchars(implode(', ', array_filter(array_map(
                fn ($userId) => Repo::user()->get($userId, true)?->getFullName(), array_keys($dossier->editors))))),        ], $extra);
    }

    public static function guidelinesUrl($context, Dossier $dossier): string
    {
        return $dossier->guidelinesUrl ?: self::pageUrl($context, 'about', 'submissions');
    }

    /**
     * The planned contributions as an HTML list (working titles, with the contributors' names if the dossier shows them).
     */
    public static function contributionsList(Dossier $dossier): string
    {
        $items = [];
        foreach (DossierRepository::getContributions($dossier->id) as $c) {
            if (in_array($c->status, [ContributionStatus::DECLINED, ContributionStatus::DROPPED], true)) {
                continue;
            }
            $user = $c->userId ? Repo::user()->get($c->userId, true) : null;
            $parts = [];
            if ($dossier->showContributors && $user) {
                $parts[] = htmlspecialchars($user->getFullName());
            }
            if ($c->workingTitle !== '') {
                $parts[] = '<em>' . htmlspecialchars($c->workingTitle) . '</em>';
            }
            if ($parts) {
                $items[] = '<li>' . implode(': ', $parts) . '</li>';
            }
        }
        return $items ? '<ul>' . implode('', $items) . '</ul>' : '';
    }

    /**
     * Subject and body of a template, with the common variables filled in and the late ones kept.
     * @param string $mailableClass One of self::MAILABLES
     * @return array [subject, body]
     */
    public static function compose($context, string $mailableClass, Dossier $dossier, ?User $sender, array $extra = []): array
    {
        $locale = $context->getPrimaryLocale();
        $template = Repo::emailTemplate()->getByKey($context->getId(), $mailableClass::getEmailTemplateKey());
        $params = self::getParams($context, $dossier, $sender, $extra);
        foreach (self::LATE_VARIABLES as $late) {
            $params[$late] ??= '{$' . $late . '}';
        }
        return [
            Mail::compileParams((string) $template?->getLocalizedData('subject', $locale), $params),
            Mail::compileParams((string) $template?->getLocalizedData('body', $locale), $params),
        ];
    }

    /**
     * Values of the late variables for a contribution's contributor.
     */
    public static function lateParams($context, Dossier $dossier, ?Contribution $c, User $recipient): array
    {
        $params = ['recipientName' => htmlspecialchars($recipient->getFullName())];
        if ($c) {
            $declineUrl = $c->linkKey ? self::pageUrl($context, DossierHandler::PAGE, 'decline', [$c->linkKey]) : '';
            $allowDecline = (bool) Settings::get($context->getId(), Settings::ALLOW_DECLINE) && $declineUrl;
            $params += [
                'workingTitle' => htmlspecialchars($c->workingTitle),
                'deadline' => self::formatDate($context, $c->getEffectiveDeadline($dossier)),
                'submissionUrl' => $c->linkKey ? self::pageUrl($context, DossierHandler::PAGE, 'submit', [$c->linkKey]) : '',
                'declineUrl' => $allowDecline ? $declineUrl : '',
                'declineLink' => $allowDecline
                    ? '<p>' . htmlspecialchars(__('plugins.generic.dossierManager.email.declineLink', [], $context->getPrimaryLocale()))
                        . '<br /><a href="' . $declineUrl . '">' . $declineUrl . '</a></p>'
                    : '',
            ];
        }
        return $params;
    }

    /**
     * Send an email to one person and record it in the dossier's history.
     * @param ?User $sender null: automatic email from the journal contact
     * @return bool Whether it was sent (false: emails are switched off)
     */
    public static function send($context, string $mailableClass, int $logType, Dossier $dossier, ?Contribution $c,
            User $recipient, ?User $sender, string $subject, string $body): bool
    {
        $late = self::lateParams($context, $dossier, $c, $recipient);
        // Cleaning the edited HTML URL-encodes the braces of placeholders in links (href="%7B$submissionUrl%7D")
        $body = preg_replace('/%7B\$([A-Za-z]+)%7D/i', '{\$$1}', $body);
        $subject = Mail::compileParams($subject, $late);
        $body = Mail::compileParams($body, $late);

        $mailable = new $mailableClass($context, []);
        $from = self::setFrom($mailable, $context, $sender);
        $mailable->recipients([$recipient])->subject($subject)->body($body);
        $sent = !Settings::emailsSuppressed($context->getId());
        if ($sent) {
            Mail::send($mailable);
        }
        DossierLog::email($dossier->id, $logType, $sender, $recipient, $from,
            $sent ? $subject : __('plugins.generic.dossierManager.email.notSent') . ' ' . $subject, $body);
        return $sent;
    }

    /**
     * Compose a template for one contribution and send it (automatic emails).
     */
    public static function sendTemplate($plugin, $context, string $mailableClass, int $logType, Dossier $dossier,
            ?Contribution $c, User $recipient, array $extra = []): bool
    {
        self::ensureTemplates($plugin, $context->getId());
        [$subject, $body] = self::compose($context, $mailableClass, $dossier, null, $extra);
        return self::send($context, $mailableClass, $logType, $dossier, $c, $recipient, null, $subject, $body);
    }

    public static function pageUrl($context, string $page, ?string $op = null, ?array $path = null): string
    {
        $request = Application::get()->getRequest();
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), $page, $op, $path);
    }

    /**
     * Set the sender of a mailable; returns the From header for the log.
     */
    protected static function setFrom($mailable, $context, ?User $sender): string
    {
        if ($sender && in_array(\PKP\mail\traits\Sender::class, class_uses($mailable))) {
            $mailable->sender($sender);
            return '"' . $sender->getFullName() . '" <' . $sender->getEmail() . '>';
        }
        $mailable->from($context->getData('contactEmail'), $context->getData('contactName'));
        return '"' . $context->getData('contactName') . '" <' . $context->getData('contactEmail') . '>';
    }
}
