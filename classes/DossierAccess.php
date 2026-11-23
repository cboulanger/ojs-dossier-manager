<?php

/**
 * @file plugins/generic/dossierManager/classes/DossierAccess.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierAccess
 *
 * @brief Who may do what on the backend pages (pure decisions, see Roles for the facts).
 *
 *  - Managers: everything.
 *  - Dossier editors (the plugin's role): create dossiers, run the ones they
 *    are responsible for, view all others.
 *  - Guest editors: view the dossiers they are a guest editor of and choose
 *    their notifications. Nothing else, unless they also have one of the roles above.
 */

namespace APP\plugins\generic\dossierManager\classes;

class DossierAccess
{
    /** Operations of DossiersHandler open to guest editors */
    const GUEST_OPS = ['index', 'view', 'notifyMode'];

    /**
     * May the user call an operation of the backend pages at all?
     * @param bool $canManage Manager or Dossier editor
     * @param bool $isGuestAnywhere Guest editor of a dossier of the journal
     */
    public static function mayCallOp(bool $canManage, bool $isGuestAnywhere, string $op): bool
    {
        return $canManage || ($isGuestAnywhere && in_array($op, self::GUEST_OPS, true));
    }

    /**
     * May the user view a dossier?
     */
    public static function canView(bool $canManage, bool $isResponsible, bool $isGuest): bool
    {
        return $canManage || $isResponsible || $isGuest;
    }

    /**
     * May the user change a dossier, its contributions and its people, and send invitations?
     */
    public static function canEdit(bool $isManager, bool $isResponsible): bool
    {
        return $isManager || $isResponsible;
    }

    /**
     * May the user choose how they are notified about a dossier?
     */
    public static function canSetNotifyMode(bool $isResponsible, bool $isGuest): bool
    {
        return $isResponsible || $isGuest;
    }

    /**
     * May the user see the links to the submissions' editorial workflow?
     * Guest editors are not assigned to the submissions, so OJS would refuse them.
     */
    public static function canOpenSubmissions(bool $isManager, bool $isGuest): bool
    {
        return $isManager || !$isGuest;
    }
}
