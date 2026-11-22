<?php

/**
 * @file plugins/generic/dossierManager/classes/DossierLog.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierLog
 *
 * @brief The history of a dossier: events and emails, stored in OJS's event
 *  and email logs with a plugin-specific association type.
 *
 *  Event messages are locale keys; they may use {$recipientName} (the person
 *  concerned) and {$subject} (the contribution or a detail).
 */

namespace APP\plugins\generic\dossierManager\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\log\EmailLogEntry;
use PKP\user\User;

class DossierLog
{
    /** Association type for log entries about a dossier (plugin-specific) */
    const ASSOC_TYPE = 0x1F1D001;

    /** Event types: the suffix of the locale key plugins.generic.dossierManager.history.event.<name> */
    const EVENTS = [
        'created' => 0x1F1D101,
        'edited' => 0x1F1D102,
        'cancelled' => 0x1F1D103,
        'reopened' => 0x1F1D104,
        'contributionAdded' => 0x1F1D105,
        'contributionEdited' => 0x1F1D106,
        'contributionRemoved' => 0x1F1D107,
        'contributionDropped' => 0x1F1D108,
        'contributionRestored' => 0x1F1D109,
        'contributorChanged' => 0x1F1D10A,
        'contributorCreated' => 0x1F1D10B,
        'invited' => 0x1F1D10C,
        'declined' => 0x1F1D10D,
        'declinedByEditor' => 0x1F1D10E,
        'linkRenewed' => 0x1F1D10F,
        'remindersPaused' => 0x1F1D110,
        'remindersResumed' => 0x1F1D111,
        'submissionStarted' => 0x1F1D112,
        'submitted' => 0x1F1D113,
        'detached' => 0x1F1D114,
        'moved' => 0x1F1D115,
        'notAccepted' => 0x1F1D116,
        'published' => 0x1F1D117,
        'reminderSent' => 0x1F1D118,
        'overdueSent' => 0x1F1D119,
        'maxReminders' => 0x1F1D11A,
        'deadlineChanged' => 0x1F1D11B,
        'targetChanged' => 0x1F1D11C,
        'editorsChanged' => 0x1F1D11D,
        'reordered' => 0x1F1D11E,
    ];

    /** Email event types */
    const EMAIL_INVITATION = 0x1F1D201;
    const EMAIL_REMINDER = 0x1F1D202;
    const EMAIL_OVERDUE = 0x1F1D203;
    const EMAIL_NOTIFICATION = 0x1F1D204;
    const EMAIL_DIGEST = 0x1F1D205;

    const EMAIL_LABELS = [
        self::EMAIL_INVITATION => 'plugins.generic.dossierManager.history.email.invitation',
        self::EMAIL_REMINDER => 'plugins.generic.dossierManager.history.email.reminder',
        self::EMAIL_OVERDUE => 'plugins.generic.dossierManager.history.email.overdue',
        self::EMAIL_NOTIFICATION => 'plugins.generic.dossierManager.history.email.notification',
        self::EMAIL_DIGEST => 'plugins.generic.dossierManager.history.email.digest',
    ];

    /** Marks steps taken automatically or through a link */
    const VIA_LINK = 'viaLink';
    const AUTOMATIC = 'automatic';

    /**
     * Log an event of a dossier.
     * @param string $event Key of self::EVENTS
     * @param ?User $actor The user who caused it (null: automatic)
     * @param ?string $person Name of the person concerned
     * @param ?string $subject Contribution or detail
     * @param ?string $via self::VIA_LINK or self::AUTOMATIC
     */
    public static function event(int $dossierId, string $event, ?User $actor, ?string $person = null, ?string $subject = null, ?string $via = null): void
    {
        $actorName = $actor?->getFullName();
        if ($via) {
            $label = __('plugins.generic.dossierManager.history.' . $via);
            $actorName = $actorName ? $actorName . ' (' . $label . ')' : $label;
        }
        $entry = Repo::eventLog()->newDataObject([
            'assocType' => self::ASSOC_TYPE,
            'assocId' => $dossierId,
            'eventType' => self::EVENTS[$event],
            'userId' => $actor?->getId(),
            'dateLogged' => Core::getCurrentDate(),
            'message' => 'plugins.generic.dossierManager.history.event.' . $event,
            'isTranslated' => false,
            'userFullName' => $actorName,
            'recipientName' => $person ?? '',
            'subject' => $subject ?? '',
        ]);
        Repo::eventLog()->add($entry);
    }

    /**
     * Log a sent (or suppressed) email.
     */
    public static function email(int $dossierId, int $eventType, ?User $sender, User|string $recipient,
            string $from, string $subject, string $body): void
    {
        $entry = new EmailLogEntry();
        $entry->assocType = self::ASSOC_TYPE;
        $entry->assocId = $dossierId;
        $entry->eventType = $eventType;
        $entry->senderId = $sender?->getId();
        $entry->dateSent = Core::getCurrentDate();
        $entry->fromAddress = $from;
        $entry->recipients = $recipient instanceof User
            ? '"' . $recipient->getFullName() . '" <' . $recipient->getEmail() . '>'
            : $recipient;
        $entry->ccRecipients = '';
        $entry->bccRecipients = '';
        $entry->subject = $subject;
        $entry->body = $body;
        $entry->save();
        if ($recipient instanceof User) {
            DB::table('email_log_users')->insert(['email_log_id' => $entry->id, 'user_id' => $recipient->getId()]);
        }
    }

    /**
     * All entries of a dossier, newest first.
     * @return array[] date, type (event|email), label, actor; for emails also recipients, subject, body
     */
    public static function getHistory(int $dossierId): array
    {
        $history = [];
        foreach (Repo::eventLog()->getCollector()->filterByAssoc(self::ASSOC_TYPE, [$dossierId])->getMany() as $event) {
            $history[] = [
                'date' => $event->getDateLogged(),
                'id' => (int) $event->getId(),
                'type' => 'event',
                'label' => $event->getTranslatedMessage(),
                'actor' => $event->getData('userFullName') ?: __('plugins.generic.dossierManager.history.automatic'),
            ];
        }
        foreach (EmailLogEntry::withAssocType(self::ASSOC_TYPE)->withAssocId($dossierId)->get() as $email) {
            $history[] = [
                'date' => (string) $email->dateSent,
                'id' => (int) $email->id,
                'type' => 'email',
                'label' => __(self::EMAIL_LABELS[(int) $email->eventType] ?? 'plugins.generic.dossierManager.history.email'),
                'actor' => $email->senderId ? $email->senderFullName : __('plugins.generic.dossierManager.history.automatic'),
                'recipients' => $email->recipients,
                'subject' => $email->subject,
                'body' => $email->body,
            ];
        }
        usort($history, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date'])
            ?: (($b['type'] === 'email') <=> ($a['type'] === 'email'))
            ?: $b['id'] <=> $a['id']);
        return $history;
    }
}
