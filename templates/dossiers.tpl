{**
 * plugins/generic/dossierManager/templates/dossiers.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * List of dossiers: all of the journal ("Planned dossiers") or the user's ("My dossiers").
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
<div class="app__contentPanel dmPanel" v-pre>
	{if $canManage}
	<div class="dmToolbar">
		<a class="pkp_button" href="{url page=$dmPage op="create"}">{translate key="plugins.generic.dossierManager.menu.create"}</a>
	</div>
	{/if}
	{if !$rows}
		<p>{if $mine}{translate key="plugins.generic.dossierManager.noOwnDossiers"}{else}{translate key="plugins.generic.dossierManager.noDossiers"}{/if}</p>
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
							{if $row.isGuest}<span class="dmBadge">{translate key="plugins.generic.dossierManager.guest.badge"}</span>{/if}
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
