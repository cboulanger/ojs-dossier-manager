<?php

/**
 * @file plugins/generic/dossierManager/classes/ReminderPolicy.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ReminderPolicy
 *
 * @brief Which reminder, if any, an open contribution is due for today:
 *  - "before": once, in the N days before the deadline (not if the invitation
 *    went out less than two days ago);
 *  - "overdue": the day after the deadline, then every M days, at most a
 *    maximum number of times (0 = unlimited).
 */

namespace APP\plugins\generic\dossierManager\classes;

class ReminderPolicy
{
    const BEFORE = 'before';
    const OVERDUE = 'overdue';

    /** Minimum days between the invitation and the reminder before the deadline */
    const MIN_DAYS_AFTER_INVITATION = 2;

    /**
     * @param array $c deadline (effective, Y-m-d), dateInvited, reminderSent, overdueCount, lastOverdue
     * @param string $today Y-m-d
     * @return ?string self::BEFORE, self::OVERDUE or null
     */
    public static function due(array $c, string $today, int $daysBefore, int $interval, int $max): ?string
    {
        if (empty($c['deadline']) || empty($c['dateInvited'])) {
            return null;
        }
        $deadline = substr($c['deadline'], 0, 10);
        if ($today > $deadline) {
            if ($interval <= 0 || ($max > 0 && (int) $c['overdueCount'] >= $max)) {
                return null;
            }
            if (empty($c['lastOverdue']) || self::days(substr($c['lastOverdue'], 0, 10), $today) >= $interval) {
                return self::OVERDUE;
            }
            return null;
        }
        if ($daysBefore <= 0 || !empty($c['reminderSent'])) {
            return null;
        }
        if (self::days($today, $deadline) <= $daysBefore
                && self::days(substr($c['dateInvited'], 0, 10), $today) >= self::MIN_DAYS_AFTER_INVITATION) {
            return self::BEFORE;
        }
        return null;
    }

    /** Days from $from to $to (Y-m-d) */
    public static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }
}
