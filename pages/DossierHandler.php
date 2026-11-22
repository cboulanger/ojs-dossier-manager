<?php

/**
 * @file plugins/generic/dossierManager/pages/DossierHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierHandler
 *
 * @brief Public pages of a contribution's personal link:
 *  - dossier/submit/{key}: start (or continue) the submission;
 *  - dossier/decline/{key}: decline the invitation;
 *  and the wizard section's actions (attach/detach a submission).
 *
 *  The link is not a login: opening a page changes nothing, every change is a POST.
 */

namespace APP\plugins\generic\dossierManager\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\dossierManager\classes\Contribution;
use APP\plugins\generic\dossierManager\classes\ContributionStatus;
use APP\plugins\generic\dossierManager\classes\Dossier;
use APP\plugins\generic\dossierManager\classes\DossierMailer;
use APP\plugins\generic\dossierManager\classes\DossierRepository;
use APP\plugins\generic\dossierManager\classes\DossierWorkflow;
use APP\plugins\generic\dossierManager\classes\Settings;
use APP\plugins\generic\dossierManager\DossierManagerPlugin;
use APP\template\TemplateManager;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

class DossierHandler extends Handler
{
    const PAGE = 'dossier';

    /** @var DossierManagerPlugin */
    public $plugin;

    public function __construct($plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function index($args, $request)
    {
        $request->redirect(null, 'index');
    }

    /**
     * The submission link page.
     */
    public function submit($args, $request)
    {
        $key = (string) ($args[0] ?? '');
        [$dossier, $c, $problem] = $this->resolve($request, $key);
        $vars = ['key' => $key, 'problem' => $problem];
        if (!$problem) {
            $vars += $this->describe($request, $dossier, $c);
            $user = $request->getUser();
            $status = DossierRepository::contributionStatus($c);
            $contributor = Repo::user()->get($c->userId, true);
            $vars['contributorName'] = $contributor?->getFullName();
            $vars['linkState'] = match (true) {
                ContributionStatus::isSubmitted($status) => 'submitted',
                !$user => 'login',
                $user->getId() !== $c->userId => 'otherUser',
                $status === ContributionStatus::IN_PROGRESS => 'continue',
                default => 'start',
            };
            if ($vars['linkState'] === 'login') {
                $vars['loginUrl'] = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'login', null, null,
                    ['source' => $request->getRequestPath()]);
                $vars['lostPasswordUrl'] = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'login', 'lostPassword');
                $vars['contributorEmail'] = self::maskEmail((string) $contributor?->getEmail());
            }
            if ($vars['linkState'] === 'otherUser') {
                $vars['currentUserName'] = $user->getFullName();
                // signOut prepends the host and a slash to the source
                $vars['logoutUrl'] = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'login', 'signOut', null,
                    ['source' => ltrim($request->getRequestPath(), '/')]);
            }
            if ($vars['linkState'] === 'continue') {
                $vars['continueUrl'] = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'submission', null, null, ['id' => $c->submissionId]);
            }
            if ($vars['linkState'] === 'submitted' && $user && $user->getId() === $c->userId) {
                $vars['submissionsUrl'] = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'dashboard', 'mySubmissions');
            }
            $vars['allowDecline'] = (bool) Settings::get($dossier->contextId, Settings::ALLOW_DECLINE) && ContributionStatus::isOpen($status);
        }
        return $this->display($request, 'link.tpl', $vars);
    }

    /**
     * Start the submission: remember the contribution and go to OJS's submission start page,
     * where the new submission is attached to it (DossierManagerPlugin::onSubmissionAdded()).
     */
    public function start($args, $request)
    {
        $key = (string) $request->getUserVar('key');
        if (!$request->isPost() || !$request->checkCSRF()) {
            $request->redirect(null, self::PAGE, 'submit', [$key]);
        }
        [$dossier, $c, $problem] = $this->resolve($request, $key);
        $user = $request->getUser();
        if ($problem || !$user || $user->getId() !== $c->userId) {
            $request->redirect(null, self::PAGE, 'submit', [$key]);
        }
        if ($c->submissionId && DossierRepository::contributionStatus($c) === ContributionStatus::IN_PROGRESS) {
            $request->redirect(null, 'submission', null, null, ['id' => $c->submissionId]);
        }
        if (!DossierWorkflow::canInvite($c)) {
            $request->redirect(null, self::PAGE, 'submit', [$key]);
        }
        $request->getSession()->put(DossierManagerPlugin::SESSION_CONTRIBUTION, $c->id);
        $request->redirect(null, 'submission');
    }

    /**
     * Decline page (GET) and the decline itself (POST).
     */
    public function decline($args, $request)
    {
        $key = (string) ($args[0] ?? $request->getUserVar('key'));
        [$dossier, $c, $problem] = $this->resolve($request, $key);
        $vars = ['key' => $key, 'problem' => $problem];
        if (!$problem) {
            $status = DossierRepository::contributionStatus($c);
            if (!Settings::get($dossier->contextId, Settings::ALLOW_DECLINE) || !ContributionStatus::isOpen($status)) {
                $request->redirect(null, self::PAGE, 'submit', [$key]);
            }
            if ($request->isPost() && $request->checkCSRF()) {
                $contributor = Repo::user()->get($c->userId, true);
                DossierWorkflow::decline($this->plugin, $request->getContext(), $dossier, $c,
                    trim((string) $request->getUserVar('reason')), $contributor, true);
                $vars['done'] = true;
            }
            $vars += $this->describe($request, $dossier, $c);
        }
        return $this->display($request, 'decline.tpl', $vars);
    }

    /**
     * Wizard section: attach the submission to one of the user's open contributions.
     */
    public function attach($args, $request)
    {
        $submissionId = (int) $request->getUserVar('submissionId');
        $user = $request->getUser();
        if ($request->isPost() && $request->checkCSRF() && $user && $this->isOwnIncompleteSubmission($submissionId, $user->getId())) {
            $c = DossierRepository::getContribution((int) $request->getUserVar('contributionId'));
            $dossier = $c ? DossierRepository::getDossier($c->dossierId, $request->getContext()->getId()) : null;
            if ($dossier && !$dossier->cancelled && $c->userId === $user->getId() && !$c->submissionId
                    && DossierWorkflow::canInvite($c) && !DossierRepository::getContributionBySubmission($submissionId)) {
                DossierWorkflow::attach($request->getContext(), $dossier, $c, Repo::submission()->get($submissionId), $user);
            }
        }
        $request->redirect(null, 'submission', null, null, ['id' => $submissionId]);
    }

    /**
     * Wizard section: detach the (incomplete) submission from its contribution.
     */
    public function detach($args, $request)
    {
        $submissionId = (int) $request->getUserVar('submissionId');
        $user = $request->getUser();
        if ($request->isPost() && $request->checkCSRF() && $user && $this->isOwnIncompleteSubmission($submissionId, $user->getId())) {
            $c = DossierRepository::getContributionBySubmission($submissionId);
            $dossier = $c ? DossierRepository::getDossier($c->dossierId, $request->getContext()->getId()) : null;
            if ($dossier && $c->userId === $user->getId()) {
                DossierWorkflow::detach($dossier, $c, $user);
            }
        }
        $request->redirect(null, 'submission', null, null, ['id' => $submissionId]);
    }

    protected function isOwnIncompleteSubmission(int $submissionId, int $userId): bool
    {
        $info = DossierRepository::submissionInfo($submissionId);
        return $info && $info['progress'] !== ''
            && StageAssignment::withSubmissionIds([$submissionId])->withRoleIds([Role::ROLE_ID_AUTHOR])->withUserId($userId)->exists();
    }

    /**
     * The dossier and contribution of a link, or the reason why the link doesn't work.
     * @return array [?Dossier, ?Contribution, ?string problem (locale key)]
     */
    protected function resolve($request, string $key): array
    {
        $c = DossierRepository::getContributionByKey($key);
        $dossier = $c ? DossierRepository::getDossier($c->dossierId, $request->getContext()->getId()) : null;
        if (!$c || !$dossier || !$c->userId) {
            return [null, null, 'plugins.generic.dossierManager.link.invalid'];
        }
        if ($dossier->cancelled) {
            return [$dossier, $c, 'plugins.generic.dossierManager.link.cancelled'];
        }
        $status = DossierRepository::contributionStatus($c);
        if ($status === ContributionStatus::DECLINED) {
            return [$dossier, $c, 'plugins.generic.dossierManager.link.declined'];
        }
        if ($status === ContributionStatus::DROPPED) {
            return [$dossier, $c, 'plugins.generic.dossierManager.link.dropped'];
        }
        return [$dossier, $c, null];
    }

    /** What the link pages show about the dossier and the contribution */
    protected function describe($request, Dossier $dossier, Contribution $c): array
    {
        $context = $request->getContext();
        return [
            'dossierType' => $dossier->type,
            'dossierTitle' => $dossier->getLocalizedTitle(),
            'dossierDescription' => $dossier->getLocalizedDescription(),
            'workingTitle' => $c->workingTitle,
            'deadline' => DossierMailer::formatDate($context, $c->getEffectiveDeadline($dossier), \PKP\facades\Locale::getLocale()),
            'issueTitle' => ($issue = $dossier->issueId ? Repo::issue()->get($dossier->issueId) : null) ? $issue->getIssueIdentification() : '',
            'guidelinesUrl' => DossierMailer::guidelinesUrl($context, $dossier),
        ];
    }

    /** a****@example.org: enough to recognise the address, not to harvest it */
    protected static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return $local === '' ? '' : mb_substr($local, 0, 1) . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
    }

    protected function display($request, string $template, array $vars)
    {
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign($vars + ['dmPage' => self::PAGE, 'dmIntro' => $this->plugin->getTemplateResource('partials/linkIntro.tpl')]);
        return $templateMgr->display($this->plugin->getTemplateResource($template));
    }
}
