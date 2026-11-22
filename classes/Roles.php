<?php

/**
 * @file plugins/generic/dossierManager/classes/Roles.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Roles
 *
 * @brief Who may use the plugin's backend pages.
 *
 *  - Journal managers (incl. journal editors) and site admins: everything.
 *  - Dossier editors: a user group created by the plugin, base role Section
 *    editor (e.g. for section editors and guest editors). They create dossiers,
 *    run the ones they are responsible for and see all others read-only.
 *
 *  The group is created per journal when needed; its ID is kept in a plugin
 *  setting. Managers can rename it under Users & Roles.
 */

namespace APP\plugins\generic\dossierManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use PKP\security\Role;
use PKP\userGroup\relationships\UserGroupStage;
use PKP\userGroup\UserGroup;

class Roles
{
    const SETTING_GROUP_ID = 'dossierEditorGroupId';

    /**
     * The ID of the Dossier editor group, created if needed.
     */
    public static function getGroupId($plugin, int $contextId): int
    {
        $groupId = (int) $plugin->getSetting($contextId, self::SETTING_GROUP_ID);
        if ($groupId && Repo::userGroup()->contextHasGroup($contextId, $groupId)) {
            return $groupId;
        }

        $locales = Application::get()->getRequest()->getSite()->getInstalledLocales();
        $names = $abbrevs = [];
        foreach ($locales as $locale) {
            $names[$locale] = __('plugins.generic.dossierManager.role.dossierEditor', [], $locale);
            $abbrevs[$locale] = __('plugins.generic.dossierManager.role.dossierEditor.abbrev', [], $locale);
        }
        $userGroup = new UserGroup([
            'roleId' => Role::ROLE_ID_SUB_EDITOR,
            'contextId' => $contextId,
            'isDefault' => false,
            'permitSelfRegistration' => false,
            'permitMetadataEdit' => true,
            'permitSettings' => false,
            'masthead' => true,
        ]);
        $userGroup->save();
        // Settings are only saved when assigned as properties (as in Repository::installLocale())
        $userGroup->name = $names;
        $userGroup->abbrev = $abbrevs;
        $userGroup->save();
        foreach ([WORKFLOW_STAGE_ID_SUBMISSION, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION] as $stageId) {
            UserGroupStage::create(['contextId' => $contextId, 'userGroupId' => $userGroup->id, 'stageId' => $stageId]);
        }
        $plugin->updateSetting($contextId, self::SETTING_GROUP_ID, $userGroup->id, 'int');
        return (int) $userGroup->id;
    }

    public static function isDossierEditor($plugin, int $contextId, ?int $userId): bool
    {
        $groupId = (int) $plugin->getSetting($contextId, self::SETTING_GROUP_ID);
        return $userId && $groupId && Repo::userGroup()->userInGroup($userId, $groupId);
    }

    /** @param int[] $userRoles Roles of the user in the journal */
    public static function isManager(array $userRoles): bool
    {
        return (bool) array_intersect([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $userRoles);
    }

    /**
     * May the user use the plugin's backend pages (managers and dossier editors)?
     * @param int[] $userRoles Roles of the user in the journal
     */
    public static function canManage($plugin, int $contextId, ?int $userId, array $userRoles): bool
    {
        return self::isManager($userRoles) || self::isDossierEditor($plugin, $contextId, $userId);
    }

    /**
     * Users who can be responsible editors: managers and dossier editors of the journal.
     * @return array userId => "Name (email)"
     */
    public static function getEditorCandidates($plugin, int $contextId): array
    {
        $groupIds = [self::getGroupId($plugin, $contextId)];
        foreach (UserGroup::withContextIds([$contextId])->withRoleIds([Role::ROLE_ID_MANAGER])->get() as $group) {
            $groupIds[] = (int) $group->getKey();
        }
        $users = Repo::user()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByUserGroupIds($groupIds)
            ->getMany();
        $candidates = [];
        foreach ($users as $user) {
            $candidates[$user->getId()] = $user->getFullName() . ' (' . $user->getEmail() . ')';
        }
        asort($candidates);
        return $candidates;
    }
}
