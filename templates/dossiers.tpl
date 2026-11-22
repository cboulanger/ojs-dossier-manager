{**
 * plugins/generic/dossierManager/templates/dossiers.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * List of dossiers ("My dossiers", or all of the journal).
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<h1 class="app__pageHeading">{if $all}{translate key="plugins.generic.dossierManager.allDossiers"}{else}{$pageTitle|escape}{/if}</h1>
<div class="app__contentPanel dmPanel" v-pre>
	<div class="dmToolbar">
		<a class="pkp_button" href="{url page=$dmPage op="create"}">{translate key="plugins.generic.dossierManager.menu.create"}</a>
		{if $all}
			<a href="{url page=$dmPage}">{translate key="plugins.generic.dossierManager.showMine"}</a>
		{else}
			<a href="{url page=$dmPage params=["all" => 1]}">{translate key="plugins.generic.dossierManager.showAll"}</a>
		{/if}
	</div>
	{if !$rows}
		<p>{if $all}{translate key="plugins.generic.dossierManager.noDossiers"}{else}{translate key="plugins.generic.dossierManager.noOwnDossiers"}{/if}</p>
	{else}
		<table class="dmTable">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.dossierManager.dossier"}</th>
					<th>{translate key="common.status"}</th>
					<th>{translate key="plugins.generic.dossierManager.targetIssue"}</th>
					<th>{translate key="plugins.generic.dossierManager.deadline"}</th>
					<th>{translate key="plugins.generic.dossierManager.progress"}</th>
					<th>{translate key="plugins.generic.dossierManager.editors"}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$rows item=row}
					<tr>
						<td>
							<div class="dmType">{$row.type|escape}</div>
							<a href="{url page=$dmPage op="view" path=$row.id}">{$row.title|escape}</a>
						</td>
						<td><span class="dmStatus dmStatus--{$row.status|escape}">{translate key="plugins.generic.dossierManager.status.`$row.status`"}</span></td>
						<td>{$row.issue|escape|default:"–"}</td>
						<td class="dmNoWrap">{if $row.deadline}{$row.deadline|date_format:$dateFormatShort}{else}–{/if}</td>
						<td class="dmNoWrap">
							{translate key="plugins.generic.dossierManager.progressCount" submitted=$row.progress.submitted planned=$row.progress.planned}
							{if $row.progress.overdue}<div class="dmWarning">{translate key="plugins.generic.dossierManager.overdueCount" number=$row.progress.overdue}</div>{/if}
						</td>
						<td>{$row.editors|escape}</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
</div>
{/block}
