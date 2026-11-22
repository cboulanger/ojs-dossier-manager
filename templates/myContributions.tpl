{**
 * plugins/generic/dossierManager/templates/myContributions.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The current user's planned contributions to dossiers.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
<div class="app__contentPanel dmPanel" v-pre>
	{if !$rows}
		<p>{translate key="plugins.generic.dossierManager.myContributions.empty"}</p>
	{else}
		<table class="dmTable">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.dossierManager.dossier"}</th>
					<th>{translate key="plugins.generic.dossierManager.contribution.workingTitle"}</th>
					<th>{translate key="plugins.generic.dossierManager.deadline"}</th>
					<th>{translate key="common.status"}</th>
					<th><span class="pkp_screen_reader">{translate key="grid.columns.actions"}</span></th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$rows item=row}
					<tr>
						<td><div class="dmType">{$row.dossierType|escape}</div>{$row.dossierTitle|escape}</td>
						<td>{$row.workingTitle|escape|default:"–"}</td>
						<td class="dmNoWrap">{if $row.deadline}<span class="{if $row.overdue}dmWarning{/if}">{$row.deadline|date_format:$dateFormatShort}</span>{else}–{/if}</td>
						<td><span class="dmStatus dmStatus--{$row.status|escape}">{translate key="plugins.generic.dossierManager.contributionStatus.`$row.status`"}</span></td>
						<td>
							{if $row.link}
								<a class="pkp_button" href="{$row.link|escape}">{if $row.status == 'inProgress'}{translate key="plugins.generic.dossierManager.link.continue"}{else}{translate key="plugins.generic.dossierManager.link.start"}{/if}</a>
							{/if}
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
		<p><a href="{$submissionsUrl|escape}">{translate key="plugins.generic.dossierManager.myContributions.submissions"}</a></p>
	{/if}
</div>
{/block}
