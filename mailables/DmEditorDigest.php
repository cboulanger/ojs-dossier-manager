<?php

/**
 * @file plugins/generic/dossierManager/mailables/DmEditorDigest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DmEditorDigest
 *
 * @brief Daily summary of changes for the responsible editors of a dossier.
 */

namespace APP\plugins\generic\dossierManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;

use PKP\security\Role;

class DmEditorDigest extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.dmEditorDigest.name';
    protected static ?string $description = 'emails.dmEditorDigest.description';
    protected static ?string $emailTemplateKey = 'DM_EDITOR_DIGEST';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    public function __construct(Journal $context, array $emailParams = [])
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
