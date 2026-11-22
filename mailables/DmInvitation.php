<?php

/**
 * @file plugins/generic/dossierManager/mailables/DmInvitation.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DmInvitation
 *
 * @brief Invitation to contribute to a dossier, with the personal submission link. Sent by a responsible editor, who can edit it first.
 */

namespace APP\plugins\generic\dossierManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

class DmInvitation extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'emails.dmInvitation.name';
    protected static ?string $description = 'emails.dmInvitation.description';
    protected static ?string $emailTemplateKey = 'DM_INVITATION';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams = [])
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
