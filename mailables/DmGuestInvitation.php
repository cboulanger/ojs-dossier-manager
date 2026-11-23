<?php

/**
 * @file plugins/generic/dossierManager/mailables/DmGuestInvitation.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DmGuestInvitation
 *
 * @brief Tells a new guest editor about the dossier, with a link to its status page.
 *  Sent when a responsible editor adds them (or resends it).
 */

namespace APP\plugins\generic\dossierManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

class DmGuestInvitation extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'emails.dmGuestInvitation.name';
    protected static ?string $description = 'emails.dmGuestInvitation.description';
    protected static ?string $emailTemplateKey = 'DM_GUEST_INVITATION';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER];

    public function __construct(Journal $context, array $emailParams = [])
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
