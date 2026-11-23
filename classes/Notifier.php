<?php

/**
 * @file plugins/generic/dossierManager/classes/Notifier.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Notifier
 *
 * @brief Notices to the responsible and guest editors of a dossier. Each one chooses
 *  per dossier:
 *  - default: submissions, declines and exhausted reminders immediately, the rest in a daily digest;
 *  - immediate: everything immediately;
 *  - digest: everything in the daily digest.
 *  The editor who made a change is not told about it.
 */

namespace APP\plugins\generic\dossierManager\classes;

use APP\facades\Repo;
use APP\plugins\generic\dossierManager\mailables\DmEditorDigest;
use APP\plugins\generic\dossierManager\mailables\DmEditorNotification;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\user\User;

class Notifier
{
    const MODE_DEFAULT = 'default';
    const MODE_IMMEDIATE = 'immediate';
    const MODE_DIGEST = 'digest';
    const MODES = [self::MODE_DEFAULT, self::MODE_IMMEDIATE, self::MODE_DIGEST];

    /** Events sent immediately in the default mode */
    const IMMEDIATE_EVENTS = ['submitted', 'declined', 'maxReminders'];

    /**
     * Notify the dossier's responsible editors of an event.
     * @param string $event Suffix of the locale key plugins.generic.dossierManager.notify.<event>
     * @param array $params Values for the message (plain text, escaped here)
     */
    public static function notify($plugin, $context, Dossier $dossier, string $event, array $params = [], ?User $actor = null): void
    {
        $locale = $context->getPrimaryLocale();
        $message = htmlspecialchars(__('plugins.generic.dossierManager.notify.' . $event,
            $params + ['actor' => $actor ? $actor->getFullName() : __('plugins.generic.dossierManager.history.automatic', [], $locale)], $locale));
        foreach ($dossier->getNotifiedEditors() as $userId => $mode) {
            if ($actor && $actor->getId() === $userId) {
                continue;
            }
            $editor = Repo::user()->get($userId, true);
            if (!$editor || $editor->getDisabled()) {
                continue;
            }
            $immediate = $mode === self::MODE_IMMEDIATE
                || ($mode !== self::MODE_DIGEST && in_array($event, self::IMMEDIATE_EVENTS, true));
            if ($immediate) {
                DossierMailer::sendTemplate($plugin, $context, DmEditorNotification::class, DossierLog::EMAIL_NOTIFICATION,
                    $dossier, null, $editor, ['eventText' => $message]);
            } else {
                DB::table('dm_digest')->insert(['dossier_id' => $dossier->id, 'user_id' => $userId,
                    'message' => $message, 'date_created' => Core::getCurrentDate()]);
            }
        }
    }

    /**
     * Send the collected notices of a journal, one email per editor.
     * @return int Number of digests sent
     */
    public static function sendDigests($plugin, $context): int
    {
        $rows = DB::table('dm_digest as g')
            ->join('dm_dossiers as d', 'd.dossier_id', '=', 'g.dossier_id')
            ->where('d.context_id', $context->getId())
            ->orderBy('g.user_id')->orderBy('g.dossier_id')->orderBy('g.digest_id')
            ->select('g.*')->get();
        $count = 0;
        foreach ($rows->groupBy('user_id') as $userId => $userRows) {
            $editor = Repo::user()->get((int) $userId, true);
            if ($editor && !$editor->getDisabled()) {
                $html = '';
                $dossiers = [];
                foreach ($userRows->groupBy('dossier_id') as $dossierId => $dossierRows) {
                    $dossier = DossierRepository::getDossier((int) $dossierId);
                    if (!$dossier) {
                        continue;
                    }
                    $dossiers[] = $dossier;
                    $url = DossierMailer::pageUrl($context, \APP\plugins\generic\dossierManager\pages\DossiersHandler::PAGE, 'view', [$dossier->id]);
                    $html .= '<p><strong><a href="' . $url . '">' . htmlspecialchars($dossier->getLocalizedTitle($context->getPrimaryLocale())) . '</a></strong></p><ul>';
                    foreach ($dossierRows as $row) {
                        $html .= '<li>' . $row->message . ' <small>(' . htmlspecialchars(DossierMailer::formatDate($context, $row->date_created)) . ')</small></li>';
                    }
                    $html .= '</ul>';
                }
                if ($dossiers) {
                    DossierMailer::ensureTemplates($plugin, $context->getId());
                    [$subject, $body] = DossierMailer::compose($context, DmEditorDigest::class, $dossiers[0], null, ['digest' => $html]);
                    // Logged in the history of the first dossier; the body lists all
                    DossierMailer::send($context, DmEditorDigest::class, DossierLog::EMAIL_DIGEST, $dossiers[0], null, $editor, null, $subject, $body);
                    $count++;
                }
            }
            DB::table('dm_digest')->whereIn('digest_id', $userRows->pluck('digest_id')->all())->delete();
        }
        return $count;
    }
}
