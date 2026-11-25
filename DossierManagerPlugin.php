<?php

/**
 * @file plugins/generic/dossierManager/DossierManagerPlugin.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierManagerPlugin
 *
 * @brief Plan dossiers (special issues, thematic sections, review symposia, ...)
 *  with commissioned contributions: invitations with personal submission links,
 *  tracking of the submissions, reminders.
 */

namespace APP\plugins\generic\dossierManager;

use APP\core\Application;
use APP\facades\Repo;
use APP\pages\submission\SubmissionHandler;
use APP\plugins\generic\dossierManager\classes\ContributionStatus;
use APP\plugins\generic\dossierManager\classes\DossierLog;
use APP\plugins\generic\dossierManager\classes\DossierMailer;
use APP\plugins\generic\dossierManager\classes\DossierReminder;
use APP\plugins\generic\dossierManager\classes\DossierRepository;
use APP\plugins\generic\dossierManager\classes\DossierWorkflow;
use APP\plugins\generic\dossierManager\classes\Notifier;
use APP\plugins\generic\dossierManager\classes\Roles;
use APP\plugins\generic\dossierManager\pages\DossierHandler;
use APP\plugins\generic\dossierManager\pages\DossiersHandler;
use APP\plugins\generic\dossierManager\pages\MyContributionsHandler;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class DossierManagerPlugin extends GenericPlugin implements HasTaskScheduler
{
    /** Session key: the contribution whose submission link was used to start a submission */
    const SESSION_CONTRIBUTION = 'dossierManager.contributionId';

    public function getName()
    {
        return 'DossierManagerPlugin';
    }

    public function getDisplayName()
    {
        return __('plugins.generic.dossierManager.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.dossierManager.description');
    }

    public function getInstallMigration()
    {
        return new DossierManagerSchemaMigration();
    }

    public function getInstallEmailTemplatesFile()
    {
        return $this->getPluginPath() . '/emailTemplates.xml';
    }

    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new DossierReminder())
            ->daily()
            ->name(DossierReminder::class)
            ->withoutOverlapping();
    }

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && Config::getVar('general', 'installed')) {
            $this->upgradeSchema();
        }
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->loadPageHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->addBackendMenu(...));
            Hook::add('Mailer::Mailables', $this->addMailables(...));

            Hook::add('Submission::add', $this->onSubmissionAdded(...));
            Hook::add('Submission::edit', $this->onSubmissionEdited(...));
            Hook::add('Submission::updateStatus', $this->onSubmissionStatus(...));
            Hook::add('Submission::delete', $this->onSubmissionDeleted(...));
            Hook::add('Publication::publish', $this->onPublished(...));

            Hook::add('TemplateManager::display', $this->addToSubmissionWizard(...));
            Hook::add('Template::SubmissionWizard::Section', $this->addWizardSection(...));
            Hook::add('Template::SubmissionWizard::Section::Review', $this->addWizardReview(...));
        }
        return $success;
    }

    /**
     * Create or upgrade the plugin's tables (installation only runs the migration once).
     */
    protected function upgradeSchema(): void
    {
        $version = (int) $this->getSetting(Application::SITE_CONTEXT_ID, 'schemaVersion');
        if ($version >= DossierManagerSchemaMigration::SCHEMA_VERSION || !$this->getCurrentVersion()) {
            return;
        }
        (new DossierManagerSchemaMigration())->up();
    }

    /**
     * Create the Dossier editor and Guest editor groups when the plugin is enabled in a journal.
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);
        $context = Application::get()->getRequest()->getContext();
        if ($enabled && $context) {
            Roles::getGroupId($this, $context->getId());
            Roles::getGuestGroupId($this, $context->getId());
        }
    }

    public function loadPageHandler(string $hookName, array $args): bool
    {
        $page = $args[0];
        $handlers = [
            DossiersHandler::PAGE => DossiersHandler::class,
            DossierHandler::PAGE => DossierHandler::class,
            MyContributionsHandler::PAGE => MyContributionsHandler::class,
        ];
        if (isset($handlers[$page])) {
            $handler = &$args[3];
            $handler = new $handlers[$page]($this);
            return true;
        }
        return false;
    }

    public function addMailables(string $hookName, array $args): void
    {
        foreach (DossierMailer::MAILABLES as $mailableClass) {
            if (!$args[0]->contains($mailableClass)) {
                $args[0]->push($mailableClass);
            }
        }
    }

    /**
     * Left menu: "Dossiers" for journal editors and dossier editors, "My dossiers" for
     * responsible and guest editors, "My dossier contributions" for contributors.
     */
    public function addBackendMenu(string $hookName, array $args): bool
    {
        $request = Application::get()->getRequest();
        $router = $request->getRouter();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$router || !$router->getHandler()) {
            return false;
        }
        $templateMgr = TemplateManager::getManager($request);
        $menu = (array) $templateMgr->getState('menu');
        $page = $request->getRequestedPage();
        $op = $request->getRequestedOp() ?: 'index';

        $openContributions = count(array_filter(DossierRepository::getContributionsByUser($context->getId(), $user->getId()),
            fn ($c) => ContributionStatus::isOpen(DossierRepository::contributionStatus($c))));
        if ($openContributions || $page === MyContributionsHandler::PAGE) {
            $entry = [
                'name' => __('plugins.generic.dossierManager.myContributions.title') . ($openContributions ? ' (' . $openContributions . ')' : ''),
                'url' => $router->url($request, null, MyContributionsHandler::PAGE),
                'isCurrent' => $page === MyContributionsHandler::PAGE,
                'icon' => 'FileText',
            ];
            $menu = self::insertMenuEntry($menu, 'dossierManagerMyContributions', $entry, 'mySubmissions', true);
        }

        $onPage = $page === DossiersHandler::PAGE;
        // A dossier page belongs to "My dossiers" if the user is one of its (guest) editors, otherwise to "Planned dossiers"
        $myDossiers = DossierRepository::getDossiers($context->getId(), $user->getId());
        $dossierOps = ['view', 'compose', 'edit'];
        $requestedId = (int) ($request->getRequestedArgs()[0] ?? 0);
        $onMyDossier = $onPage && in_array($op, $dossierOps, true)
            && in_array($requestedId, array_map(fn ($d) => $d->id, $myDossiers), true);

        // "My dossiers (x)": for responsible and guest editors, x = dossiers in progress
        $onMine = $onPage && $op === 'mine';
        if ($myDossiers || $onMine) {
            $active = DossierRepository::countActiveDossiers($myDossiers);
            $entry = [
                'name' => __('plugins.generic.dossierManager.menu.myDossiers') . ($active ? ' (' . $active . ')' : ''),
                'url' => $router->url($request, null, DossiersHandler::PAGE, 'mine'),
                'isCurrent' => $onMine || $onMyDossier,
                'icon' => 'Catalog',
            ];
            $menu = self::insertMenuEntry($menu, 'dossierManagerMyDossiers', $entry,
                ['dossierManagerMyContributions', 'mySubmissions', 'dashboards'], true);
        }

        $userRoles = (array) $router->getHandler()->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        $canManage = Roles::canManage($this, $context->getId(), $user->getId(), $userRoles);
        // Journal editors see all dossiers (read-only unless they can manage); guest editors only have "My dossiers" (above)
        if (Roles::canSeeAll($this, $context->getId(), $user->getId(), $userRoles)) {
            $submenu = [
                'plannedDossiers' => ['name' => __('plugins.generic.dossierManager.menu.plannedDossiers'),
                    'url' => $router->url($request, null, DossiersHandler::PAGE),
                    'isCurrent' => $onPage && ($op === 'index' || (in_array($op, $dossierOps, true) && !$onMyDossier))],
            ];
            if ($canManage) {
                $submenu['create'] = ['name' => __('plugins.generic.dossierManager.menu.create'),
                    'url' => $router->url($request, null, DossiersHandler::PAGE, 'create'),
                    'isCurrent' => $onPage && $op === 'create'];
            }
            if (Roles::isManager($userRoles)) {
                $submenu['settings'] = ['name' => __('plugins.generic.dossierManager.menu.settings'),
                    'url' => $router->url($request, null, DossiersHandler::PAGE, 'settings'),
                    'isCurrent' => $onPage && $op === 'settings'];
            }
            $entry = ['name' => __('plugins.generic.dossierManager.menu.dossiers'), 'icon' => 'Catalog', 'submenu' => $submenu];
            $menu = self::insertMenuEntry($menu, 'dossierManager', $entry, 'settings', false);
        }
        $templateMgr->setState(['menu' => $menu]);
        return false;
    }

    /**
     * Insert a menu entry before or after another one (the first of several that exists), or at the end.
     * @param string|string[] $anchors
     */
    protected static function insertMenuEntry(array $menu, string $key, array $entry, string|array $anchors, bool $after): array
    {
        $pos = false;
        foreach ((array) $anchors as $anchor) {
            if (($pos = array_search($anchor, array_keys($menu), true)) !== false) {
                break;
            }
        }
        if ($pos === false) {
            return $menu + [$key => $entry];
        }
        $pos += $after ? 1 : 0;
        return array_slice($menu, 0, $pos, true) + [$key => $entry] + array_slice($menu, $pos, null, true);
    }

    /* ---------- Submission lifecycle ---------- */

    /**
     * A submission was created after the contributor used their submission link: attach it.
     */
    public function onSubmissionAdded(string $hookName, array $args): bool
    {
        $submission = $args[0]; /** @var Submission $submission */
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user || !$context) {
            return false;
        }
        $session = $request->getSession();
        $contributionId = (int) $session->get(self::SESSION_CONTRIBUTION);
        if (!$contributionId) {
            return false;
        }
        $session->forget(self::SESSION_CONTRIBUTION);
        $c = DossierRepository::getContribution($contributionId);
        $dossier = $c ? DossierRepository::getDossier($c->dossierId, $context->getId()) : null;
        if ($c && $dossier && !$dossier->cancelled && $c->userId === $user->getId() && !$c->submissionId
                && DossierWorkflow::canInvite($c)) {
            DossierWorkflow::attach($context, $dossier, $c, Repo::submission()->get($submission->getId()), $user);
        }
        return false;
    }

    /**
     * Detect the completion of the submission wizard (Repo::submission()->submit() clears submissionProgress).
     */
    public function onSubmissionEdited(string $hookName, array $args): bool
    {
        [$newSubmission, $submission, $params] = $args;
        if (!array_key_exists('submissionProgress', $params) || $params['submissionProgress'] !== ''
                || (string) $submission->getData('submissionProgress') === '') {
            return false;
        }
        $c = DossierRepository::getContributionBySubmission($submission->getId());
        $dossier = $c ? DossierRepository::getDossier($c->dossierId) : null;
        if ($dossier) {
            $context = Application::getContextDAO()->getById($dossier->contextId);
            // The hook runs before the submission is stored: use the new object
            DossierWorkflow::onSubmitted($this, $context, $dossier, $c, $newSubmission);
        }
        return false;
    }

    /**
     * A contribution's submission was declined in the editorial workflow.
     */
    public function onSubmissionStatus(string $hookName, array $args): bool
    {
        $newStatus = $args[0];
        $submission = $args[2];
        if ((int) $newStatus !== Submission::STATUS_DECLINED || (int) $submission->getData('status') === Submission::STATUS_DECLINED) {
            return false;
        }
        $c = DossierRepository::getContributionBySubmission($submission->getId());
        $dossier = $c ? DossierRepository::getDossier($c->dossierId) : null;
        if ($dossier) {
            $context = Application::getContextDAO()->getById($dossier->contextId);
            $user = Application::get()->getRequest()->getUser();
            DossierLog::event($dossier->id, 'notAccepted', $user, null, $c->workingTitle);
            Notifier::notify($this, $context, $dossier, 'notAccepted',
                ['title' => $submission->getCurrentPublication()?->getLocalizedFullTitle() ?: $c->workingTitle], $user);
        }
        return false;
    }

    public function onSubmissionDeleted(string $hookName, array $args): bool
    {
        $submission = $args[0];
        $c = DossierRepository::getContributionBySubmission($submission->getId());
        if ($c) {
            $c->submissionId = null;
            DossierRepository::saveContribution($c);
            DossierLog::event($c->dossierId, 'detached', Application::get()->getRequest()->getUser(), null, $c->workingTitle);
        }
        return false;
    }

    /**
     * A contribution was published; when the whole dossier is published, the editors are told.
     */
    public function onPublished(string $hookName, array $args): bool
    {
        $submission = $args[2];
        $c = DossierRepository::getContributionBySubmission($submission->getId());
        $dossier = $c ? DossierRepository::getDossier($c->dossierId) : null;
        if (!$dossier) {
            return false;
        }
        DossierLog::event($dossier->id, 'published', null, null, $c->workingTitle, DossierLog::AUTOMATIC);
        $open = array_filter(DossierRepository::getContributions($dossier->id), function ($other) use ($c) {
            if ($other->id === $c->id) {
                return false;
            }
            $status = DossierRepository::contributionStatus($other);
            return !in_array($status, [ContributionStatus::PUBLISHED, ContributionStatus::DECLINED, ContributionStatus::DROPPED, ContributionStatus::NOT_ACCEPTED], true);
        });
        if (!$open) {
            $context = Application::getContextDAO()->getById($dossier->contextId);
            Notifier::notify($this, $context, $dossier, 'dossierPublished', ['title' => $dossier->getLocalizedTitle()]);
        }
        return false;
    }

    /* ---------- Submission wizard ---------- */

    /**
     * Add the section "Dossier" to the step "For the Editors" of the submission wizard,
     * for submissions of a contribution and for users with open contributions.
     */
    public function addToSubmissionWizard(string $hookName, array $params): bool
    {
        $request = Application::get()->getRequest();
        if ($request->getRequestedPage() !== 'submission' || $request->getRequestedOp() === 'saved') {
            return false;
        }
        $handler = $request->getRouter()->getHandler();
        $submission = $handler?->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$submission || !$submission->getData('submissionProgress') || !$context || !$user) {
            return false;
        }

        $attached = DossierRepository::getContributionBySubmission($submission->getId());
        $data = ['submissionId' => $submission->getId(), 'attached' => null, 'choices' => []];
        if ($attached && ($dossier = DossierRepository::getDossier($attached->dossierId))) {
            $data['attached'] = $this->wizardRow($dossier, $attached);
        } else {
            foreach (DossierRepository::getContributionsByUser($context->getId(), $user->getId()) as $c) {
                $dossier = DossierRepository::getDossier($c->dossierId);
                if (!$c->submissionId && DossierWorkflow::canInvite($c) && $dossier && !$dossier->cancelled) {
                    $data['choices'][] = $this->wizardRow($dossier, $c);
                }
            }
        }
        if (!$data['attached'] && !$data['choices']) {
            return false;
        }

        $templateMgr = $params[0]; /** @var TemplateManager $templateMgr */
        $steps = array_map(function ($step) {
            if ($step['id'] === 'editors') {
                $step['sections'][] = [
                    'id' => 'dossierManager',
                    'name' => __('plugins.generic.dossierManager.wizard.name'),
                    'type' => SubmissionHandler::SECTION_TYPE_TEMPLATE,
                ];
            }
            return $step;
        }, (array) $templateMgr->getState('steps'));
        $templateMgr->setState(['steps' => $steps]);
        $templateMgr->assign('dossierWizard', $data);
        return false;
    }

    protected function wizardRow($dossier, $c): array
    {
        return [
            'contributionId' => $c->id,
            'dossierType' => $dossier->type,
            'dossierTitle' => $dossier->getLocalizedTitle(),
            'workingTitle' => $c->workingTitle,
            'deadline' => $c->getEffectiveDeadline($dossier),
        ];
    }

    public function addWizardSection(string $hookName, array $params): bool
    {
        $smarty = $params[1];
        $output = &$params[2];
        if ($smarty->getTemplateVars('dossierWizard')) {
            $output .= sprintf('<template v-else-if="section.id === \'dossierManager\'">%s</template>',
                $smarty->fetch($this->getTemplateResource('wizardSection.tpl')));
        }
        return false;
    }

    public function addWizardReview(string $hookName, array $params): bool
    {
        $step = $params[0]['step'];
        $templateMgr = $params[1];
        $output = &$params[2];
        if ($step === 'editors' && ($templateMgr->getTemplateVars('dossierWizard')['attached'] ?? null)) {
            $output .= $templateMgr->fetch($this->getTemplateResource('wizardReview.tpl'));
        }
        return false;
    }
}
