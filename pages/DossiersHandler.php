<?php

/**
 * @file plugins/generic/dossierManager/pages/DossiersHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossiersHandler
 *
 * @brief Backend pages (left menu "Dossiers"): the list of dossiers, the form,
 *  the dossier page with its contributions and history, the invitation email,
 *  and the settings. GET shows, POST changes and redirects.
 *
 *  Guest editors only reach the list, the (read-only) dossier page and their
 *  notification setting; see DossierAccess.
 */

namespace APP\plugins\generic\dossierManager\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\notification\NotificationManager;
use APP\plugins\generic\dossierManager\classes\Contribution;
use APP\plugins\generic\dossierManager\classes\ContributionStatus;
use APP\plugins\generic\dossierManager\classes\Dossier;
use APP\plugins\generic\dossierManager\classes\DossierAccess;
use APP\plugins\generic\dossierManager\classes\DossierLog;
use APP\plugins\generic\dossierManager\classes\DossierMailer;
use APP\plugins\generic\dossierManager\classes\DossierRepository;
use APP\plugins\generic\dossierManager\classes\DossierWorkflow;
use APP\plugins\generic\dossierManager\classes\Notifier;
use APP\plugins\generic\dossierManager\classes\Roles;
use APP\plugins\generic\dossierManager\classes\Settings;
use APP\plugins\generic\dossierManager\mailables\DmInvitation;
use APP\template\TemplateManager;
use PKP\core\PKPString;
use PKP\notification\Notification;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class DossiersHandler extends Handler
{
    const PAGE = 'dossiers';

    const OPS = ['index', 'create', 'edit', 'save', 'view', 'dossierAction', 'addContribution', 'updateContribution',
        'contributionAction', 'compose', 'sendInvitations', 'notifyMode', 'users', 'settings', 'saveSettings'];

    public $_isBackendPage = true;

    /** @var \APP\plugins\generic\dossierManager\DossierManagerPlugin */
    public $plugin;

    public function __construct($plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->addRoleAssignment([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR], self::OPS);
        // Guest editors have the Guest editor group (base role Reader); access is checked per dossier
        $this->addRoleAssignment([Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_REVIEWER],
            DossierAccess::GUEST_OPS);
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        if (!parent::authorize($request, $args, $roleAssignments)) {
            return false;
        }
        $userId = $request->getUser()?->getId();
        $canManage = Roles::canManage($this->plugin, $request->getContext()->getId(), $userId, $this->userRoles());
        return DossierAccess::mayCallOp($canManage,
            !$canManage && $userId && DossierRepository::isGuestEditorInContext($request->getContext()->getId(), $userId),
            (string) ($request->getRequestedOp() ?: 'index'));
    }

    protected function userRoles(): array
    {
        return (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
    }

    protected function isManager(): bool
    {
        return Roles::isManager($this->userRoles());
    }

    /** Manager or Dossier editor (may create dossiers and view all) */
    protected function canManage($request): bool
    {
        return Roles::canManage($this->plugin, $request->getContext()->getId(), $request->getUser()->getId(), $this->userRoles());
    }

    protected function canEdit(Dossier $dossier, $request): bool
    {
        return DossierAccess::canEdit($this->isManager(), $dossier->isEditor($request->getUser()->getId()));
    }

    /* ---------- Lists ---------- */

    public function index($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        $canManage = $this->canManage($request);
        $all = $canManage && (bool) $request->getUserVar('all');
        $rows = [];
        foreach (DossierRepository::getDossiers($context->getId(), $all ? null : $user->getId()) as $dossier) {
            $contributions = DossierRepository::getContributions($dossier->id);
            $counts = $this->progress($dossier, $contributions);
            $rows[] = [
                'id' => $dossier->id,
                'type' => $dossier->type,
                'title' => $dossier->getLocalizedTitle(),
                'status' => DossierRepository::dossierStatus($dossier, $contributions),
                'issue' => $this->issueTitle($dossier->issueId),
                'deadline' => $dossier->deadline,
                'editors' => implode(', ', $this->editorNames($dossier)),
                'progress' => $counts,
                'isGuest' => $dossier->isGuestEditor($user->getId()),
            ];
        }
        return $this->display($request, 'dossiers.tpl', 'plugins.generic.dossierManager.menu.myDossiers', [
            'rows' => $rows,
            'all' => $all,
            'canManage' => $canManage,
        ]);
    }

    /**
     * Counts for the progress column: submitted of planned, overdue.
     */
    protected function progress(Dossier $dossier, array $contributions): array
    {
        $today = date('Y-m-d');
        $planned = $submitted = $overdue = 0;
        foreach ($contributions as $c) {
            $status = DossierRepository::contributionStatus($c);
            if (in_array($status, [ContributionStatus::DECLINED, ContributionStatus::DROPPED], true)) {
                continue;
            }
            $planned++;
            if (ContributionStatus::isSubmitted($status)) {
                $submitted++;
            } elseif (ContributionStatus::isOpen($status) && ($deadline = $c->getEffectiveDeadline($dossier)) && $deadline < $today) {
                $overdue++;
            }
        }
        return ['planned' => $planned, 'submitted' => $submitted, 'overdue' => $overdue];
    }

    /* ---------- Dossier form ---------- */

    public function create($args, $request)
    {
        $dossier = new Dossier();
        $dossier->contextId = $request->getContext()->getId();
        $dossier->editors = [$request->getUser()->getId() => Notifier::MODE_DEFAULT];
        return $this->showForm($request, $dossier);
    }

    public function edit($args, $request)
    {
        $dossier = $this->requireDossier($request, (int) ($args[0] ?? 0), true);
        return $this->showForm($request, $dossier);
    }

    protected function showForm($request, Dossier $dossier, array $errors = [])
    {
        $context = $request->getContext();
        $types = Settings::get($context->getId(), Settings::TYPES);
        if ($dossier->type !== '' && !in_array($dossier->type, $types, true)) {
            $types[] = $dossier->type;
        }
        [$days, $interval, $max] = [Settings::get($context->getId(), Settings::REMINDER_DAYS),
            Settings::get($context->getId(), Settings::OVERDUE_INTERVAL), Settings::get($context->getId(), Settings::OVERDUE_MAX)];
        return $this->display($request, 'dossierForm.tpl',
            $dossier->id ? 'plugins.generic.dossierManager.edit' : 'plugins.generic.dossierManager.menu.create', [
                'dossier' => $dossier,
                'types' => $types,
                'formLocales' => $context->getSupportedFormLocaleNames(),
                'primaryLocale' => $context->getPrimaryLocale(),
                'issues' => $this->futureIssues($context->getId(), $dossier->issueId),
                'sections' => $this->sections($context->getId()),
                'editorCandidates' => $this->editorCandidates($context->getId(), $dossier),
                'guestEditors' => $this->userNames(array_keys($dossier->guestEditors), true),
                'usersUrl' => $request->getRouter()->url($request, null, self::PAGE, 'users'),
                'inviteGuests' => !$request->isPost() || (bool) $request->getUserVar('inviteGuests'),
                'defaultGuidelinesUrl' => DossierMailer::pageUrl($context, 'about', 'submissions'),
                'journalReminders' => ['days' => $days, 'interval' => $interval, 'max' => $max],
                'errors' => $errors,
            ]);
    }

    public function save($args, $request)
    {
        $this->requirePost($request);
        $context = $request->getContext();
        $user = $request->getUser();
        $dossierId = (int) $request->getUserVar('dossierId');
        $dossier = $dossierId ? $this->requireDossier($request, $dossierId, true) : new Dossier();
        $old = clone $dossier;
        $dossier->contextId = $context->getId();

        $errors = [];
        $dossier->type = trim((string) $request->getUserVar('type'));
        $dossier->title = array_map(fn ($v) => trim((string) $v), (array) $request->getUserVar('title'));
        $dossier->description = array_map(fn ($v) => self::cleanHtml((string) $v), (array) $request->getUserVar('description'));
        if (Dossier::localize($dossier->title, $context->getPrimaryLocale()) === '') {
            $errors['title'] = __('plugins.generic.dossierManager.form.titleRequired');
        }
        $dossier->issueId = (int) $request->getUserVar('issueId') ?: null;
        if ($dossier->issueId && !array_key_exists($dossier->issueId, $this->futureIssues($context->getId(), $old->issueId))) {
            $errors['issueId'] = __('plugins.generic.dossierManager.form.issueInvalid');
        }
        $dossier->sectionId = (int) $request->getUserVar('sectionId') ?: null;
        if ($dossier->sectionId && !array_key_exists($dossier->sectionId, $this->sections($context->getId()))) {
            $errors['sectionId'] = __('plugins.generic.dossierManager.form.sectionInvalid');
        }
        $dossier->deadline = self::parseDate($request->getUserVar('deadline'), $errors, 'deadline');
        $url = trim((string) $request->getUserVar('guidelinesUrl'));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['guidelinesUrl'] = __('plugins.generic.dossierManager.form.urlInvalid');
        }
        $dossier->guidelinesUrl = $url ?: null;
        $dossier->notes = trim((string) $request->getUserVar('notes'));
        $dossier->showContributors = (bool) $request->getUserVar('showContributors');
        foreach (['reminderDays', 'overdueInterval', 'overdueMax'] as $field) {
            $value = trim((string) $request->getUserVar($field));
            if ($value !== '' && !ctype_digit($value)) {
                $errors[$field] = __('plugins.generic.dossierManager.form.numberInvalid');
            }
            $dossier->$field = $value === '' ? null : (int) $value;
        }
        $candidates = Roles::getEditorCandidates($this->plugin, $context->getId());
        $editorIds = array_values(array_filter(array_map('intval', (array) $request->getUserVar('editors')),
            fn ($id) => isset($candidates[$id]) || $old->isEditor($id)));
        if (!$editorIds) {
            $errors['editors'] = __('plugins.generic.dossierManager.form.editorsRequired');
        }
        $dossier->editors = [];
        foreach ($editorIds as $id) {
            $dossier->editors[$id] = $old->editors[$id] ?? Notifier::MODE_DEFAULT;
        }
        // Someone who is not a manager can't remove themselves (they would lose access)
        if (!$this->isManager() && !$dossier->isEditor($user->getId())) {
            $dossier->editors[$user->getId()] = $old->editors[$user->getId()] ?? Notifier::MODE_DEFAULT;
        }

        // Guest editors: those kept, and one new one (from the users or a new person).
        // Someone who becomes responsible is no longer a guest editor.
        $keep = array_map('intval', (array) $request->getUserVar('guestEditors'));
        $dossier->guestEditors = [];
        foreach ($old->guestEditors as $id => $mode) {
            if (in_array($id, $keep, true) && !$dossier->isEditor($id)) {
                $dossier->guestEditors[$id] = $mode;
            }
        }
        $newGuest = $this->guestFromRequest($request, $errors);
        if ($newGuest && $dossier->isEditor($newGuest['id'] ?? null)) {
            $errors['guestEditors'] = __('plugins.generic.dossierManager.form.guestIsEditor');
        }

        if ($errors) {
            return $this->showForm($request, $dossier, $errors);
        }

        if ($newGuest && !isset($newGuest['id'])) {
            [$guestUser, $created] = DossierWorkflow::findOrCreateUser($context, $newGuest['givenName'], $newGuest['familyName'], $newGuest['email'], false);
            if ($dossier->isEditor($guestUser->getId())) {
                $errors['guestEditors'] = __('plugins.generic.dossierManager.form.guestIsEditor');
                return $this->showForm($request, $dossier, $errors);
            }
            $newGuest = ['id' => $guestUser->getId(), 'created' => $created];
        }
        if ($newGuest) {
            $dossier->guestEditors[$newGuest['id']] ??= $old->guestEditors[$newGuest['id']] ?? $old->editors[$newGuest['id']] ?? Notifier::MODE_DEFAULT;
        }

        if (!$dossier->id) {
            $dossier->createdBy = $user->getId();
            DossierRepository::saveDossier($dossier);
            DossierLog::event($dossier->id, 'created', $user, null, $dossier->getLocalizedTitle());
        } else {
            DossierRepository::saveDossier($dossier);
        }
        if (!empty($newGuest['created'])) {
            $guestUser = Repo::user()->get($newGuest['id'], true);
            DossierLog::event($dossier->id, 'contributorCreated', $user, $guestUser->getFullName(), $guestUser->getEmail());
        }
        if ($old->id) {
            $this->logChanges($request, $old, $dossier);
        } elseif ($dossier->guestEditors) {
            DossierLog::event($dossier->id, 'guestEditorsChanged', $user, null, implode(', ', $this->userNames(array_keys($dossier->guestEditors))));
        }

        // New guest editors: the role, and the invitation if wanted
        $added = array_diff(array_keys($dossier->guestEditors), array_keys($old->guestEditors));
        $invited = 0;
        foreach ($added as $guestId) {
            Roles::addToGuestGroup($this->plugin, $context->getId(), $guestId);
            $guest = Repo::user()->get($guestId, true);
            if ($guest && !$guest->getDisabled() && $request->getUserVar('inviteGuests')) {
                $invited += DossierWorkflow::inviteGuestEditor($this->plugin, $context, $dossier, $guest, $user) ? 1 : 0;
            }
        }
        if ($invited) {
            $this->flash($request, __('plugins.generic.dossierManager.guest.invited', ['number' => $invited]));
        }
        $this->flash($request, __('common.changesSaved'));
        $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
    }

    /**
     * Log and announce the changes of a dossier; reset reminders and move submissions where needed.
     */
    protected function logChanges($request, Dossier $old, Dossier $new): void
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if ($old->deadline !== $new->deadline) {
            DossierWorkflow::resetReminders($new);
            $date = DossierMailer::formatDate($context, $new->deadline) ?: '–';
            DossierLog::event($new->id, 'deadlineChanged', $user, null, $date);
            Notifier::notify($this->plugin, $context, $new, 'deadlineChanged', ['date' => $date], $user);
        }
        if ($old->issueId !== $new->issueId || $old->sectionId !== $new->sectionId) {
            $target = trim($this->issueTitle($new->issueId) . ' / ' . ($this->sections($context->getId())[$new->sectionId] ?? ''), ' /') ?: '–';
            DossierLog::event($new->id, 'targetChanged', $user, null, $target);
            $moved = DossierWorkflow::moveSubmittedToTarget($context, $new, $user);
            Notifier::notify($this->plugin, $context, $new, 'targetChanged', ['target' => $target, 'moved' => $moved], $user);
        }
        if (array_keys($old->editors) != array_keys($new->editors)) {
            $names = implode(', ', $this->editorNames($new));
            DossierLog::event($new->id, 'editorsChanged', $user, null, $names);
            DossierWorkflow::assignEditorsToSubmitted($this->plugin, $context, $new, $user);
            Notifier::notify($this->plugin, $context, $new, 'editorsChanged', ['names' => $names], $user);
        }
        if (array_keys($old->guestEditors) != array_keys($new->guestEditors)) {
            $names = implode(', ', $this->userNames(array_keys($new->guestEditors))) ?: '–';
            DossierLog::event($new->id, 'guestEditorsChanged', $user, null, $names);
            Notifier::notify($this->plugin, $context, $new, 'guestEditorsChanged', ['names' => $names], $user);
        }
        $other = ['type', 'title', 'description', 'guidelinesUrl', 'notes', 'showContributors', 'reminderDays', 'overdueInterval', 'overdueMax'];
        foreach ($other as $field) {
            if ($old->$field != $new->$field) {
                DossierLog::event($new->id, 'edited', $user);
                Notifier::notify($this->plugin, $context, $new, 'edited', [], $user);
                break;
            }
        }
    }

    /* ---------- Dossier page ---------- */

    public function view($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        $dossier = $this->requireDossier($request, (int) ($args[0] ?? 0), false);
        $contributions = DossierRepository::getContributions($dossier->id);
        $canEdit = $this->canEdit($dossier, $request);
        $isGuest = $dossier->isGuestEditor($user->getId());
        $canOpenSubmissions = DossierAccess::canOpenSubmissions($this->isManager(), $isGuest);
        $rows = [];
        $today = date('Y-m-d');
        foreach ($contributions as $c) {
            $contributor = $c->userId ? Repo::user()->get($c->userId, true) : null;
            $status = DossierRepository::contributionStatus($c);
            $info = DossierRepository::submissionInfo($c->submissionId);
            $deadline = $c->getEffectiveDeadline($dossier);
            $submission = $info ? Repo::submission()->get($info['id']) : null;
            $rows[] = [
                'id' => $c->id,
                'workingTitle' => $c->workingTitle,
                'contributor' => $contributor ? ['id' => $contributor->getId(), 'name' => $contributor->getFullName(), 'email' => $contributor->getEmail()] : null,
                'status' => $status,
                'stage' => $info && $info['progress'] === '' ? $this->stageName($info['stageId']) : null,
                'submissionUrl' => $submission && $canOpenSubmissions ? Repo::submission()->getWorkflowUrlByUserRoles($submission, $user->getId()) : null,
                'submissionTitle' => $submission ? (string) $submission->getCurrentPublication()?->getLocalizedFullTitle() : null,
                'deadline' => $deadline,
                'ownDeadline' => $c->deadline,
                'overdue' => ContributionStatus::isOpen($status) && $deadline && $deadline < $today,
                'overdueCount' => $c->overdueCount,
                'reminderSent' => $c->reminderSent,
                'paused' => $c->remindersPaused,
                'note' => $c->note,
                'declineReason' => $c->declineReason,
                'link' => $c->linkKey && $canEdit ? DossierMailer::pageUrl($context, DossierHandler::PAGE, 'submit', [$c->linkKey]) : null,
                'dateInvited' => $c->dateInvited,
                'canInvite' => DossierWorkflow::canInvite($c) && !$dossier->cancelled,
                'canRemove' => !$c->submissionId && !$c->dateInvited,
                'open' => ContributionStatus::isOpen($status),
            ];
        }
        $section = $dossier->sectionId ? ($this->sections($context->getId())[$dossier->sectionId] ?? null) : null;
        return $this->display($request, 'dossier.tpl', null, [
            'pageTitle' => $dossier->getLocalizedTitle(),
            'dossier' => $dossier,
            'description' => $dossier->getLocalizedDescription(),
            'status' => DossierRepository::dossierStatus($dossier, $contributions),
            'issue' => $this->issueTitle($dossier->issueId),
            'section' => $section,
            'editors' => implode(', ', $this->editorNames($dossier)),
            'guestEditors' => $this->userNames(array_keys($dossier->guestEditors), true),
            'isGuest' => $isGuest,
            'guidelinesUrl' => DossierMailer::guidelinesUrl($context, $dossier),
            'rows' => $rows,
            'progress' => $this->progress($dossier, $contributions),
            'canEdit' => $canEdit,
            'invitationBlocker' => DossierWorkflow::invitationBlocker($dossier),
            'uninvited' => count(array_filter($rows, fn ($r) => $r['canInvite'] && !$r['dateInvited'])),
            'notifyMode' => $dossier->getNotifiedEditors()[$user->getId()] ?? null,
            'notifyModes' => Notifier::MODES,
            'history' => DossierLog::getHistory($dossier->id),
            'usersUrl' => $request->getRouter()->url($request, null, self::PAGE, 'users'),
            'reminderSettings' => Settings::reminderSettings($dossier),
        ]);
    }

    /**
     * Cancel or reopen a dossier.
     */
    public function dossierAction($args, $request)
    {
        $this->requirePost($request);
        $dossier = $this->requireDossier($request, (int) $request->getUserVar('dossierId'), true);
        $user = $request->getUser();
        switch ($request->getUserVar('action')) {
            case 'cancel':
                $dossier->cancelled = true;
                DossierRepository::saveDossier($dossier);
                DossierLog::event($dossier->id, 'cancelled', $user);
                Notifier::notify($this->plugin, $request->getContext(), $dossier, 'cancelled', [], $user);
                break;
            case 'reopen':
                $dossier->cancelled = false;
                DossierRepository::saveDossier($dossier);
                DossierLog::event($dossier->id, 'reopened', $user);
                Notifier::notify($this->plugin, $request->getContext(), $dossier, 'reopened', [], $user);
                break;
            case 'inviteGuest':
                $guestId = (int) $request->getUserVar('userId');
                $guest = $dossier->isGuestEditor($guestId) ? Repo::user()->get($guestId, true) : null;
                if ($guest && !$guest->getDisabled()) {
                    $sent = DossierWorkflow::inviteGuestEditor($this->plugin, $request->getContext(), $dossier, $guest, $user);
                    $this->flash($request, __('plugins.generic.dossierManager.guest.invited', ['number' => $sent ? 1 : 0]));
                }
                break;
        }
        $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
    }

    /* ---------- Contributions ---------- */

    public function addContribution($args, $request)
    {
        $this->requirePost($request);
        $context = $request->getContext();
        $user = $request->getUser();
        $dossier = $this->requireDossier($request, (int) $request->getUserVar('dossierId'), true);
        $errors = [];
        $c = new Contribution();
        $c->dossierId = $dossier->id;
        $c->workingTitle = trim((string) $request->getUserVar('workingTitle'));
        $c->note = trim((string) $request->getUserVar('note'));
        $c->deadline = self::parseDate($request->getUserVar('deadline'), $errors, 'deadline');
        $contributor = $this->contributorFromRequest($request, $errors);
        if ($errors) {
            $this->flash($request, implode(' ', $errors), Notification::NOTIFICATION_TYPE_ERROR);
            $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
        }
        $c->userId = $contributor?->getId();
        $c->linkKey = DossierRepository::newLinkKey();
        DossierRepository::saveContribution($c);
        DossierLog::event($dossier->id, 'contributionAdded', $user, $contributor?->getFullName(), $c->workingTitle);
        Notifier::notify($this->plugin, $context, $dossier, 'contributionAdded',
            ['name' => $contributor?->getFullName() ?? '–', 'title' => $c->workingTitle ?: '–'], $user);
        $request->redirect(null, self::PAGE, 'view', [$dossier->id], null, 'contribution-' . $c->id);
    }

    /**
     * The contributor chosen from the users (userId) or entered as a new person.
     * @return ?\PKP\user\User null if none was given
     */
    protected function contributorFromRequest($request, array &$errors)
    {
        $userId = (int) $request->getUserVar('userId');
        if ($userId) {
            $user = Repo::user()->get($userId, true);
            if (!$user) {
                $errors[] = __('plugins.generic.dossierManager.contribution.userNotFound');
            }
            return $user;
        }
        $email = trim((string) $request->getUserVar('email'));
        $given = trim((string) $request->getUserVar('givenName'));
        $family = trim((string) $request->getUserVar('familyName'));
        if ($email === '' && $given === '' && $family === '') {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $given === '' || $family === '') {
            $errors[] = __('plugins.generic.dossierManager.contribution.newPersonInvalid');
            return null;
        }
        [$user, $created] = DossierWorkflow::findOrCreateContributor($request->getContext(), $given, $family, $email);
        if ($created) {
            DossierLog::event((int) $request->getUserVar('dossierId'), 'contributorCreated', $request->getUser(), $user->getFullName(), $user->getEmail());
        }
        return $user;
    }

    public function updateContribution($args, $request)
    {
        $this->requirePost($request);
        $context = $request->getContext();
        $user = $request->getUser();
        [$dossier, $c] = $this->requireContribution($request);
        $errors = [];
        $oldDeadline = $c->deadline;
        $c->workingTitle = trim((string) $request->getUserVar('workingTitle'));
        $c->note = trim((string) $request->getUserVar('note'));
        $c->deadline = self::parseDate($request->getUserVar('deadline'), $errors, 'deadline');
        $contributor = null;
        $changeContributor = $request->getUserVar('userId') || trim((string) $request->getUserVar('email')) !== '';
        if ($changeContributor) {
            $contributor = $this->contributorFromRequest($request, $errors);
        }
        if ($errors) {
            $this->flash($request, implode(' ', $errors), Notification::NOTIFICATION_TYPE_ERROR);
            $request->redirect(null, self::PAGE, 'view', [$dossier->id], null, 'contribution-' . $c->id);
        }
        if ($contributor && $contributor->getId() !== $c->userId) {
            if ($c->submissionId) {
                $this->flash($request, __('plugins.generic.dossierManager.contribution.cannotChangeSubmitted'), Notification::NOTIFICATION_TYPE_ERROR);
                $request->redirect(null, self::PAGE, 'view', [$dossier->id], null, 'contribution-' . $c->id);
            }
            $c->userId = $contributor->getId();
            // The old link stops working; the new contributor needs an invitation
            $c->linkKey = DossierRepository::newLinkKey();
            $c->status = ContributionStatus::PLANNED;
            $c->dateInvited = null;
            $c->reminderSent = null;
            $c->overdueCount = 0;
            $c->lastOverdue = null;
            $c->declineReason = '';
            DossierRepository::saveContribution($c);
            DossierLog::event($dossier->id, 'contributorChanged', $user, $contributor->getFullName(), $c->workingTitle);
            Notifier::notify($this->plugin, $context, $dossier, 'contributorChanged',
                ['name' => $contributor->getFullName(), 'title' => $c->workingTitle ?: '–'], $user);
        } else {
            DossierRepository::saveContribution($c);
            DossierLog::event($dossier->id, 'contributionEdited', $user, null, $c->workingTitle);
        }
        if ($oldDeadline !== $c->deadline) {
            DossierWorkflow::resetReminders($dossier, $c);
            $date = DossierMailer::formatDate($context, $c->getEffectiveDeadline($dossier)) ?: '–';
            DossierLog::event($dossier->id, 'deadlineChanged', $user, null, $c->workingTitle . ': ' . $date);
            Notifier::notify($this->plugin, $context, $dossier, 'contributionDeadlineChanged',
                ['title' => $c->workingTitle ?: '–', 'date' => $date], $user);
        }
        $request->redirect(null, self::PAGE, 'view', [$dossier->id], null, 'contribution-' . $c->id);
    }

    public function contributionAction($args, $request)
    {
        $this->requirePost($request);
        $context = $request->getContext();
        $user = $request->getUser();
        [$dossier, $c] = $this->requireContribution($request);
        $contributor = $c->userId ? Repo::user()->get($c->userId, true) : null;
        $name = $contributor?->getFullName();
        $anchor = 'contribution-' . $c->id;
        switch ($request->getUserVar('action')) {
            case 'up':
            case 'down':
                DossierRepository::moveContribution($c, $request->getUserVar('action') === 'up' ? -1 : 1);
                DossierLog::event($dossier->id, 'reordered', $user);
                break;
            case 'drop':
                $c->status = ContributionStatus::DROPPED;
                DossierRepository::saveContribution($c);
                DossierLog::event($dossier->id, 'contributionDropped', $user, $name, $c->workingTitle);
                Notifier::notify($this->plugin, $context, $dossier, 'contributionDropped', ['name' => $name ?? '–', 'title' => $c->workingTitle ?: '–'], $user);
                break;
            case 'restore':
                $c->status = $c->dateInvited ? ContributionStatus::INVITED : ContributionStatus::PLANNED;
                $c->declineReason = '';
                DossierRepository::saveContribution($c);
                DossierLog::event($dossier->id, 'contributionRestored', $user, $name, $c->workingTitle);
                break;
            case 'remove':
                if (!$c->submissionId && !$c->dateInvited) {
                    DossierRepository::deleteContribution($c->id);
                    DossierLog::event($dossier->id, 'contributionRemoved', $user, $name, $c->workingTitle);
                    Notifier::notify($this->plugin, $context, $dossier, 'contributionRemoved', ['name' => $name ?? '–', 'title' => $c->workingTitle ?: '–'], $user);
                    $anchor = null;
                }
                break;
            case 'pause':
            case 'resume':
                $c->remindersPaused = $request->getUserVar('action') === 'pause';
                DossierRepository::saveContribution($c);
                DossierLog::event($dossier->id, $c->remindersPaused ? 'remindersPaused' : 'remindersResumed', $user, $name, $c->workingTitle);
                break;
            case 'renewLink':
                $c->linkKey = DossierRepository::newLinkKey();
                DossierRepository::saveContribution($c);
                DossierLog::event($dossier->id, 'linkRenewed', $user, $name, $c->workingTitle);
                $this->flash($request, __('plugins.generic.dossierManager.contribution.linkRenewed'));
                break;
            case 'markDeclined':
                DossierWorkflow::decline($this->plugin, $context, $dossier, $c, trim((string) $request->getUserVar('reason')), $user, false);
                break;
        }
        $request->redirect(null, self::PAGE, 'view', [$dossier->id], null, $anchor);
    }

    /* ---------- Invitations ---------- */

    /**
     * The invitation email, edited once for the selected contributions
     * (?contributionId=... for one; otherwise all that have not been invited).
     */
    public function compose($args, $request)
    {
        $context = $request->getContext();
        $dossier = $this->requireDossier($request, (int) ($args[0] ?? 0), true);
        if ($blocker = DossierWorkflow::invitationBlocker($dossier)) {
            $this->flash($request, __($blocker), Notification::NOTIFICATION_TYPE_ERROR);
            $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
        }
        $only = (int) $request->getUserVar('contributionId');
        $recipients = [];
        foreach (DossierRepository::getContributions($dossier->id) as $c) {
            $selected = $only ? $c->id === $only : !$c->dateInvited;
            if ($selected && DossierWorkflow::canInvite($c)) {
                $user = Repo::user()->get($c->userId, true);
                $recipients[] = ['id' => $c->id, 'name' => $user?->getFullName(), 'email' => $user?->getEmail(),
                    'workingTitle' => $c->workingTitle, 'invited' => (bool) $c->dateInvited];
            }
        }
        if (!$recipients) {
            $this->flash($request, __('plugins.generic.dossierManager.invite.nobody'), Notification::NOTIFICATION_TYPE_WARNING);
            $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
        }
        DossierMailer::ensureTemplates($this->plugin, $context->getId());
        [$subject, $body] = DossierMailer::compose($context, DmInvitation::class, $dossier, $request->getUser());
        return $this->display($request, 'compose.tpl', 'plugins.generic.dossierManager.invite.title', [
            'dossier' => $dossier,
            'dossierTitle' => $dossier->getLocalizedTitle(),
            'recipients' => $recipients,
            'subject' => $subject,
            'body' => $body,
            'lateVariables' => DossierMailer::LATE_VARIABLES,
            'emailsSuppressed' => Settings::emailsSuppressed($context->getId()),
        ]);
    }

    public function sendInvitations($args, $request)
    {
        $this->requirePost($request);
        $context = $request->getContext();
        $dossier = $this->requireDossier($request, (int) $request->getUserVar('dossierId'), true);
        if ($blocker = DossierWorkflow::invitationBlocker($dossier)) {
            $this->flash($request, __($blocker), Notification::NOTIFICATION_TYPE_ERROR);
            $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
        }
        $ids = array_map('intval', (array) $request->getUserVar('contributionIds'));
        $contributions = array_filter(DossierRepository::getContributions($dossier->id), fn ($c) => in_array($c->id, $ids, true));
        // OJS's output filter protects {{ }} from Vue, also inside the fields of the form: undo it
        $unprotect = fn (string $text) => str_replace(['<span v-pre>{{</span>', '<span v-pre>}}</span>'], ['{{', '}}'], $text);
        $subject = trim($unprotect((string) $request->getUserVar('subject')));
        $body = PKPString::stripUnsafeHtml($unprotect((string) $request->getUserVar('body')));
        if ($subject === '' || trim(strip_tags($body)) === '') {
            $this->flash($request, __('plugins.generic.dossierManager.invite.emptyEmail'), Notification::NOTIFICATION_TYPE_ERROR);
            $request->redirect(null, self::PAGE, 'compose', [$dossier->id]);
        }
        $count = DossierWorkflow::invite($this->plugin, $context, $dossier, $contributions, $subject, $body, $request->getUser());
        $this->flash($request, __('plugins.generic.dossierManager.invite.sent', ['number' => $count]));
        $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
    }

    /* ---------- Other ---------- */

    public function notifyMode($args, $request)
    {
        $this->requirePost($request);
        $dossier = $this->requireDossier($request, (int) $request->getUserVar('dossierId'), false);
        $mode = (string) $request->getUserVar('mode');
        $userId = $request->getUser()->getId();
        if (DossierAccess::canSetNotifyMode($dossier->isEditor($userId), $dossier->isGuestEditor($userId)) && in_array($mode, Notifier::MODES, true)) {
            DossierRepository::setNotifyMode($dossier->id, $request->getUser()->getId(), $mode);
            $this->flash($request, __('common.changesSaved'));
        }
        $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
    }

    /**
     * Search the journal's users for the contributor field (JSON).
     */
    public function users($args, $request)
    {
        $term = trim((string) $request->getUserVar('term'));
        $users = [];
        if (mb_strlen($term) >= 2) {
            $collector = Repo::user()->getCollector()
                ->filterByContextIds([$request->getContext()->getId()])
                ->searchPhrase($term)
                ->limit(15);
            foreach ($collector->getMany() as $user) {
                $users[] = ['id' => $user->getId(), 'name' => $user->getFullName(), 'email' => $user->getEmail(),
                    'affiliation' => (string) $user->getLocalizedAffiliation()];
            }
        }
        header('Content-Type: application/json');
        echo json_encode($users);
        exit;
    }

    public function settings($args, $request)
    {
        if (!$this->isManager()) {
            $request->redirect(null, self::PAGE);
        }
        $contextId = $request->getContext()->getId();
        return $this->display($request, 'settings.tpl', 'plugins.generic.dossierManager.menu.settings', [
            'values' => [
                Settings::TYPES => implode("\n", Settings::get($contextId, Settings::TYPES)),
                Settings::REMINDER_DAYS => Settings::get($contextId, Settings::REMINDER_DAYS),
                Settings::OVERDUE_INTERVAL => Settings::get($contextId, Settings::OVERDUE_INTERVAL),
                Settings::OVERDUE_MAX => Settings::get($contextId, Settings::OVERDUE_MAX),
                Settings::ALLOW_DECLINE => Settings::get($contextId, Settings::ALLOW_DECLINE),
                Settings::SUPPRESS_EMAILS => Settings::get($contextId, Settings::SUPPRESS_EMAILS),
            ],
            'groupName' => Repo::userGroup()->get(Roles::getGroupId($this->plugin, $contextId))?->getLocalizedData('name'),
        ]);
    }

    public function saveSettings($args, $request)
    {
        $this->requirePost($request);
        if (!$this->isManager()) {
            $request->redirect(null, self::PAGE);
        }
        $contextId = $request->getContext()->getId();
        Settings::set($contextId, Settings::TYPES, implode("\n", Settings::parseTypes((string) $request->getUserVar(Settings::TYPES))));
        foreach ([Settings::REMINDER_DAYS, Settings::OVERDUE_INTERVAL, Settings::OVERDUE_MAX] as $name) {
            Settings::set($contextId, $name, max(0, (int) $request->getUserVar($name)));
        }
        Settings::set($contextId, Settings::ALLOW_DECLINE, (bool) $request->getUserVar(Settings::ALLOW_DECLINE));
        Settings::set($contextId, Settings::SUPPRESS_EMAILS, (bool) $request->getUserVar(Settings::SUPPRESS_EMAILS));
        $this->flash($request, __('common.changesSaved'));
        $request->redirect(null, self::PAGE, 'settings');
    }

    /* ---------- Helpers ---------- */

    protected function display($request, string $template, ?string $titleKey, array $vars)
    {
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet('dossierManager', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/dossierManager.css',
            ['contexts' => 'backend']);
        $templateMgr->addJavaScript('dossierManager', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/js/dossierManager.js',
            ['contexts' => 'backend']);
        $templateMgr->assign($vars + [
            'pageTitle' => $titleKey ? __($titleKey) : '',
            'dmPage' => self::PAGE,
            'isManager' => $this->isManager(),
            'dmContributorFields' => $this->plugin->getTemplateResource('partials/contributorFields.tpl'),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource($template));
    }

    protected function requirePost($request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $request->redirect(null, self::PAGE);
        }
    }

    protected function requireDossier($request, int $dossierId, bool $forEditing): Dossier
    {
        $dossier = $dossierId ? DossierRepository::getDossier($dossierId, $request->getContext()->getId()) : null;
        $userId = $request->getUser()->getId();
        if (!$dossier || !DossierAccess::canView($this->canManage($request), $dossier->isEditor($userId), $dossier->isGuestEditor($userId))) {
            $this->flash($request, __('plugins.generic.dossierManager.notFound'), Notification::NOTIFICATION_TYPE_ERROR);
            $request->redirect(null, self::PAGE);
        }
        if ($forEditing && !$this->canEdit($dossier, $request)) {
            $this->flash($request, __('plugins.generic.dossierManager.notResponsible'), Notification::NOTIFICATION_TYPE_ERROR);
            $request->redirect(null, self::PAGE, 'view', [$dossier->id]);
        }
        return $dossier;
    }

    /** @return array [Dossier, Contribution] */
    protected function requireContribution($request): array
    {
        $c = DossierRepository::getContribution((int) $request->getUserVar('contributionId'));
        $dossier = $this->requireDossier($request, $c?->dossierId ?? 0, true);
        return [$dossier, $c];
    }

    protected function flash($request, string $message, int $type = Notification::NOTIFICATION_TYPE_SUCCESS): void
    {
        (new NotificationManager())->createTrivialNotification($request->getUser()->getId(), $type, ['contents' => $message]);
    }

    protected static function parseDate($value, array &$errors, string $field): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $errors[$field] = __('plugins.generic.dossierManager.form.dateInvalid');
            return null;
        }
        return $value;
    }

    /**
     * Rich text from a textarea: plain text becomes paragraphs; HTML is cleaned.
     */
    protected static function cleanHtml(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($value === strip_tags($value)) {
            $paragraphs = preg_split('/\R{2,}/', $value);
            return implode('', array_map(fn ($p) => '<p>' . nl2br(htmlspecialchars(trim($p))) . '</p>', $paragraphs));
        }
        return PKPString::stripUnsafeHtml($value);
    }

    /**
     * Unpublished issues (and the current target, if it has been published since).
     * @return array issueId => title
     */
    protected function futureIssues(int $contextId, ?int $currentId): array
    {
        $issues = [];
        foreach (Repo::issue()->getCollector()->filterByContextIds([$contextId])->filterByPublished(false)->getMany() as $issue) {
            $issues[$issue->getId()] = $issue->getIssueIdentification();
        }
        if ($currentId && !isset($issues[$currentId]) && ($issue = Repo::issue()->get($currentId))) {
            $issues[$currentId] = $issue->getIssueIdentification();
        }
        return $issues;
    }

    protected function issueTitle(?int $issueId): string
    {
        $issue = $issueId ? Repo::issue()->get($issueId) : null;
        return $issue ? $issue->getIssueIdentification() : '';
    }

    /** @return array sectionId => title (including editors-only sections) */
    protected function sections(int $contextId): array
    {
        $sections = [];
        foreach (Repo::section()->getCollector()->filterByContextIds([$contextId])->getMany() as $section) {
            $sections[$section->getId()] = $section->getLocalizedTitle()
                . ($section->getEditorRestricted() ? ' (' . __('plugins.generic.dossierManager.form.editorsOnly') . ')' : '');
        }
        return $sections;
    }

    /**
     * Checkboxes of the responsible editors: the possible editors and the current ones.
     */
    protected function editorCandidates(int $contextId, Dossier $dossier): array
    {
        $candidates = Roles::getEditorCandidates($this->plugin, $contextId) + $this->editorNames($dossier);
        $list = [];
        foreach ($candidates as $id => $name) {
            $list[] = ['id' => $id, 'name' => $name, 'checked' => $dossier->isEditor($id)];
        }
        return $list;
    }

    protected function editorNames(Dossier $dossier): array
    {
        return $this->userNames(array_keys($dossier->editors));
    }

    /**
     * @param int[] $userIds
     * @param bool $withEmail "Name (email)"
     * @return array userId => name
     */
    protected function userNames(array $userIds, bool $withEmail = false): array
    {
        $names = [];
        foreach ($userIds as $userId) {
            if ($user = Repo::user()->get($userId, true)) {
                $names[$userId] = $user->getFullName() . ($withEmail ? ' (' . $user->getEmail() . ')' : '');
            }
        }
        return $names;
    }

    /**
     * The new guest editor of the dossier form: ['id' => userId] for a user of the journal,
     * ['givenName', 'familyName', 'email'] for a new person, or null if none was given.
     */
    protected function guestFromRequest($request, array &$errors): ?array
    {
        $userId = (int) $request->getUserVar('userId');
        if ($userId) {
            if (!Repo::user()->get($userId, true)) {
                $errors['guestEditors'] = __('plugins.generic.dossierManager.contribution.userNotFound');
                return null;
            }
            return ['id' => $userId];
        }
        $person = [
            'givenName' => trim((string) $request->getUserVar('givenName')),
            'familyName' => trim((string) $request->getUserVar('familyName')),
            'email' => trim((string) $request->getUserVar('email')),
        ];
        if (implode('', $person) === '') {
            return null;
        }
        if (!filter_var($person['email'], FILTER_VALIDATE_EMAIL) || $person['givenName'] === '' || $person['familyName'] === '') {
            $errors['guestEditors'] = __('plugins.generic.dossierManager.contribution.newPersonInvalid');
            return null;
        }
        if ($existing = Repo::user()->getByEmail($person['email'], true)) {
            return ['id' => $existing->getId()];
        }
        return $person;
    }

    protected function stageName(int $stageId): string
    {
        return match ($stageId) {
            WORKFLOW_STAGE_ID_SUBMISSION => __('manager.publication.submissionStage'),
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => __('manager.publication.reviewStage'),
            WORKFLOW_STAGE_ID_EDITING => __('submission.copyediting'),
            WORKFLOW_STAGE_ID_PRODUCTION => __('manager.publication.productionStage'),
            default => '',
        };
    }

}
