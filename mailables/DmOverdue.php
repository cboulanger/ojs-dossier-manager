<?php

/**
 * @file plugins/generic/dossierManager/mailables/DmOverdue.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DmOverdue
 *
 * @brief Automatic reminder after the submission deadline, repeated at an interval.
 */

namespace APP\plugins\generic\dossierManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;

use PKP\security\Role;

class DmOverdue extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.dmOverdue.name';
    protected static ?string $description = 'emails.dmOverdue.description';
    protected static ?string $emailTemplateKey = 'DM_OVERDUE';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams = [])
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
