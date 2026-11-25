<?php

/**
 * @file plugins/generic/dossierManager/classes/Roles.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Roles
 *
 * @brief The plugin's user groups and who may use the backend pages.
 *
 *  - Journal managers (incl. journal editors) and site admins: everything.
 *  - Section editors (the editorial board): see all dossiers read-only, so that
 *    the planning is transparent to the board.
 *  - Dossier editors: a user group created by the plugin, base role Section
 *    editor (e.g. for section editors). They create dossiers, run the ones
 *    they are responsible for and see all others read-only.
 *  - Guest editors: a user group created by the plugin, base role Reader, so
 *    it gives no editorial rights. Access is decided per dossier: guest editors
 *    see the dossiers they are a guest editor of, read-only, and get the same
 *    notices as the responsible editors. See DossierAccess.
 *
 *  The groups are created per journal when needed; their IDs are kept in plugin
 *  settings. Managers can rename them under Users & Roles.
 */

namespace APP\plugins\generic\dossierManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use PKP\security\Role;
use PKP\userGroup\relationships\UserGroupStage;
use PKP\core\Core;
use PKP\userGroup\UserGroup;
use PKP\userGroup\relationships\UserUserGroup;

class Roles
{
    const SETTING_GROUP_ID = 'dossierEditorGroupId';
    const SETTING_GUEST_GROUP_ID = 'guestEditorGroupId';

    /**
     * The ID of the Dossier editor group, created if needed.
     */
    public static function getGroupId($plugin, int $contextId): int
    {
        return self::ensureGroup($plugin, $contextId, self::SETTING_GROUP_ID, Role::ROLE_ID_SUB_EDITOR, 'dossierEditor',
            [WORKFLOW_STAGE_ID_SUBMISSION, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION]);
    }

    /**
     * The ID of the Guest editor group, created if needed.
     */
    public static function getGuestGroupId($plugin, int $contextId): int
    {
        return self::ensureGroup($plugin, $contextId, self::SETTING_GUEST_GROUP_ID, Role::ROLE_ID_READER, 'guestEditor', []);
    }

    /**
     * Put a user into the Guest editor group (if they aren't in it yet).
     */
    public static function addToGuestGroup($plugin, int $contextId, int $userId): void
    {
        $groupId = self::getGuestGroupId($plugin, $contextId);
        if (!UserUserGroup::withUserId($userId)->withUserGroupIds([$groupId])->withActive()->exists()) {
            UserUserGroup::create(['userId' => $userId, 'userGroupId' => $groupId,
                'dateStart' => Core::getCurrentDate(), 'dateEnd' => null, 'masthead' => true]);
        }
    }

    /**
     * A user group of the plugin, created if it doesn't exist (any more).
     * @param string $key Suffix of the locale keys plugins.generic.dossierManager.role.<key>(.abbrev)
     * @param int[] $stageIds Workflow stages the group is assigned to
     */
    protected static function ensureGroup($plugin, int $contextId, string $setting, int $roleId, string $key, array $stageIds): int
    {
        $groupId = (int) $plugin->getSetting($contextId, $setting);
        if ($groupId && Repo::userGroup()->contextHasGroup($contextId, $groupId)) {
            return $groupId;
        }

        $locales = Application::get()->getRequest()->getSite()->getInstalledLocales();
        $names = $abbrevs = [];
        foreach ($locales as $locale) {
            $names[$locale] = __('plugins.generic.dossierManager.role.' . $key, [], $locale);
            $abbrevs[$locale] = __('plugins.generic.dossierManager.role.' . $key . '.abbrev', [], $locale);
        }
        $userGroup = new UserGroup([
            'roleId' => $roleId,
            'contextId' => $contextId,
            'isDefault' => false,
            'permitSelfRegistration' => false,
            'permitMetadataEdit' => $roleId === Role::ROLE_ID_SUB_EDITOR,
            'permitSettings' => false,
            'masthead' => true,
        ]);
        $userGroup->save();
        // Settings are only saved when assigned as properties (as in Repository::installLocale())
        $userGroup->name = $names;
        $userGroup->abbrev = $abbrevs;
        $userGroup->save();
        foreach ($stageIds as $stageId) {
            UserGroupStage::create(['contextId' => $contextId, 'userGroupId' => $userGroup->id, 'stageId' => $stageId]);
        }
        $plugin->updateSetting($contextId, $setting, $userGroup->id, 'int');
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

    /** @param int[] $userRoles Roles of the user in the journal */
    public static function isJournalEditor(array $userRoles): bool
    {
        return self::isManager($userRoles) || in_array(Role::ROLE_ID_SUB_EDITOR, $userRoles);
    }

    /**
     * May the user see all dossiers of the journal (read-only unless canManage)?
     * Journal editors (managers, section editors) and dossier editors.
     * @param int[] $userRoles Roles of the user in the journal
     */
    public static function canSeeAll($plugin, int $contextId, ?int $userId, array $userRoles): bool
    {
        return self::isJournalEditor($userRoles) || self::canManage($plugin, $contextId, $userId, $userRoles);
    }

    /**
     * May the user create dossiers and use all of the plugin's backend pages (managers and dossier editors)?
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
