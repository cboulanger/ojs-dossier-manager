<?php

/**
 * @file plugins/generic/dossierManager/mailables/DmEditorNotification.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DmEditorNotification
 *
 * @brief Notice to the responsible and guest editors of a dossier about a change.
 */

namespace APP\plugins\generic\dossierManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;

use PKP\security\Role;

class DmEditorNotification extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.dmEditorNotification.name';
    protected static ?string $description = 'emails.dmEditorNotification.description';
    protected static ?string $emailTemplateKey = 'DM_EDITOR_NOTIFICATION';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_READER];

    public function __construct(Journal $context, array $emailParams = [])
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
