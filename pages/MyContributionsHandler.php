<?php

/**
 * @file plugins/generic/dossierManager/pages/MyContributionsHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MyContributionsHandler
 *
 * @brief Backend page "My contributions" (left menu): the current user's
 *  planned contributions to dossiers, with their deadlines and submission links.
 */

namespace APP\plugins\generic\dossierManager\pages;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\dossierManager\classes\ContributionStatus;
use APP\plugins\generic\dossierManager\classes\DossierMailer;
use APP\plugins\generic\dossierManager\classes\DossierRepository;
use APP\template\TemplateManager;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class MyContributionsHandler extends Handler
{
    const PAGE = 'myContributions';

    public $_isBackendPage = true;

    public $plugin;

    public function __construct($plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->addRoleAssignment([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_READER], ['index']);
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function index($args, $request)
    {
        $context = $request->getContext();
        $rows = [];
        $today = date('Y-m-d');
        foreach (DossierRepository::getContributionsByUser($context->getId(), $request->getUser()->getId()) as $c) {
            $dossier = DossierRepository::getDossier($c->dossierId);
            $status = DossierRepository::contributionStatus($c);
            if (!$dossier || in_array($status, [ContributionStatus::DROPPED, ContributionStatus::PLANNED], true)) {
                continue;
            }
            $deadline = $c->getEffectiveDeadline($dossier);
            $rows[] = [
                'dossierType' => $dossier->type,
                'dossierTitle' => $dossier->getLocalizedTitle(),
                'workingTitle' => $c->workingTitle,
                'status' => $status,
                'deadline' => $deadline,
                'overdue' => ContributionStatus::isOpen($status) && $deadline && $deadline < $today,
                'link' => ContributionStatus::isOpen($status) && $c->linkKey
                    ? DossierMailer::pageUrl($context, DossierHandler::PAGE, 'submit', [$c->linkKey]) : null,
                'open' => ContributionStatus::isOpen($status),
            ];
        }
        usort($rows, fn ($a, $b) => ($b['open'] <=> $a['open']) ?: strcmp((string) $a['deadline'], (string) $b['deadline']));
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet('dossierManager', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/dossierManager.css',
            ['contexts' => 'backend']);
        $templateMgr->assign([
            'pageTitle' => __('plugins.generic.dossierManager.myContributions.title'),
            'rows' => $rows,
            'submissionsUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'dashboard', 'mySubmissions'),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('myContributions.tpl'));
    }
}
