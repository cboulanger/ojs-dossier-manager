{**
 * plugins/generic/dossierManager/templates/dossier.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * A dossier: details, planned contributions, invitations, history.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="dmHeading" v-pre>
	<div class="dmType">{$dossier->type|escape}</div>
	<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
	<span class="dmStatus dmStatus--{$status|escape}">{translate key="plugins.generic.dossierManager.status.`$status`"}</span>
</div>

<div class="app__contentPanel dmPanel" v-pre>
	<div class="dmToolbar">
		<a href="{url page=$dmPage}">← {translate key="plugins.generic.dossierManager.menu.myDossiers"}</a>
		{if $canEdit}
			<a class="pkp_button" href="{url page=$dmPage op="edit" path=$dossier->id}">{translate key="common.edit"}</a>
			{if !$invitationBlocker && $uninvited}
				<a class="pkp_button pkp_button_primary" href="{url page=$dmPage op="compose" path=$dossier->id}">{translate key="plugins.generic.dossierManager.invite.send" number=$uninvited}</a>
			{/if}
			<form method="post" action="{url page=$dmPage op="dossierAction"}" class="dmInline"{if !$dossier->cancelled} data-dm-confirm="{translate key="plugins.generic.dossierManager.cancelConfirm"}"{/if}>
				{csrf}
				<input type="hidden" name="dossierId" value="{$dossier->id|escape}" />
				{if $dossier->cancelled}
					<button type="submit" name="action" value="reopen" class="pkp_button">{translate key="plugins.generic.dossierManager.reopen"}</button>
				{else}
					<button type="submit" name="action" value="cancel" class="pkp_button pkp_button_offset">{translate key="plugins.generic.dossierManager.cancelDossier"}</button>
				{/if}
			</form>
		{else}
			<span class="dmHint">{translate key="plugins.generic.dossierManager.readOnly"}</span>
		{/if}
	</div>

	{if $canEdit && $invitationBlocker}
		<div class="dmNotice">{translate key=$invitationBlocker}</div>
	{/if}

	<dl class="dmDetails">
		<dt>{translate key="plugins.generic.dossierManager.targetIssue"}</dt><dd>{$issue|escape|default:"–"}</dd>
		<dt>{translate key="plugins.generic.dossierManager.targetSection"}</dt><dd>{$section|escape|default:"–"}</dd>
		<dt>{translate key="plugins.generic.dossierManager.deadline"}</dt><dd>{if $dossier->deadline}{$dossier->deadline|date_format:$dateFormatLong}{else}–{/if}</dd>
		<dt>{translate key="plugins.generic.dossierManager.editors"}</dt><dd>{$editors|escape}</dd>
		<dt>{translate key="plugins.generic.dossierManager.progress"}</dt>
		<dd>
			{translate key="plugins.generic.dossierManager.progressCount" submitted=$progress.submitted planned=$progress.planned}
			{if $progress.overdue}<span class="dmWarning">· {translate key="plugins.generic.dossierManager.overdueCount" number=$progress.overdue}</span>{/if}
		</dd>
		<dt>{translate key="plugins.generic.dossierManager.reminders"}</dt>
		<dd>{translate key="plugins.generic.dossierManager.reminderSummary" days=$reminderSettings[0] interval=$reminderSettings[1] max=$reminderSettings[2]}</dd>
		<dt>{translate key="plugins.generic.dossierManager.guidelinesUrl"}</dt><dd><a href="{$guidelinesUrl|escape}" target="_blank" rel="noopener">{$guidelinesUrl|escape}</a></dd>
	</dl>
	{if $description}
		<div class="dmDescription">{$description|strip_unsafe_html}</div>
	{/if}
	{if $dossier->notes}
		<div class="dmNotes"><strong>{translate key="plugins.generic.dossierManager.notes"}:</strong> {$dossier->notes|escape|nl2br}</div>
	{/if}

	<h2>{translate key="plugins.generic.dossierManager.contributions"}</h2>
	{if !$rows}
		<p>{translate key="plugins.generic.dossierManager.noContributions"}</p>
	{else}
		<table class="dmTable dmContributions">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.dossierManager.contribution"}</th>
					<th>{translate key="common.status"}</th>
					<th>{translate key="plugins.generic.dossierManager.deadline"}</th>
					<th>{translate key="plugins.generic.dossierManager.reminders"}</th>
				</tr>
			</thead>
			<tbody>
			{foreach from=$rows item=row name=contributions}
				<tr id="contribution-{$row.id|escape}" class="{if $row.status == 'dropped' || $row.status == 'declined'}dmMuted{/if}">
					<td>
						<div class="dmWorkingTitle">{if $row.workingTitle}{$row.workingTitle|escape}{else}<em>{translate key="plugins.generic.dossierManager.contribution.noTitle"}</em>{/if}</div>
						{if $row.contributor}
							<div>{$row.contributor.name|escape} <span class="dmHint">&lt;{$row.contributor.email|escape}&gt;</span></div>
						{else}
							<div class="dmWarning">{translate key="plugins.generic.dossierManager.contribution.noContributor"}</div>
						{/if}
						{if $row.note}<div class="dmHint">{$row.note|escape}</div>{/if}
					</td>
					<td>
						<span class="dmStatus dmStatus--{$row.status|escape}">{translate key="plugins.generic.dossierManager.contributionStatus.`$row.status`"}</span>
						{if $row.stage}<div class="dmHint">{$row.stage|escape}</div>{/if}
						{if $row.submissionUrl}<div><a href="{$row.submissionUrl|escape}">{if $row.submissionTitle}{$row.submissionTitle|escape}{else}{translate key="plugins.generic.dossierManager.contribution.submission"}{/if}</a></div>{/if}
						{if $row.declineReason}<div class="dmHint"><em>{$row.declineReason|escape}</em></div>{/if}
					</td>
					<td class="dmNoWrap">
						{if $row.deadline}
							<span class="{if $row.overdue}dmWarning{/if}">{$row.deadline|date_format:$dateFormatShort}</span>
							{if $row.ownDeadline}<div class="dmHint">{translate key="plugins.generic.dossierManager.contribution.ownDeadline"}</div>{/if}
						{else}–{/if}
					</td>
					<td>
						{if $row.paused}
							<span class="dmWarning">{translate key="plugins.generic.dossierManager.contribution.paused"}</span>
						{elseif $row.open}
							{if $row.reminderSent}<div class="dmHint">{translate key="plugins.generic.dossierManager.contribution.reminderSent" date=$row.reminderSent|date_format:$dateFormatShort}</div>{/if}
							{if $row.overdueCount}<div class="dmHint">{translate key="plugins.generic.dossierManager.contribution.overdueSent" number=$row.overdueCount}</div>{/if}
						{/if}
					</td>
				</tr>
				{if $canEdit}
				<tr class="dmActionsRow{if $row.status == 'dropped' || $row.status == 'declined'} dmMuted{/if}">
					<td colspan="4">
						<div class="dmActions">
							<form method="post" action="{url page=$dmPage op="contributionAction"}" class="dmInline">
								{csrf}
								<input type="hidden" name="contributionId" value="{$row.id|escape}" />
								{if !$smarty.foreach.contributions.first}<button type="submit" name="action" value="up" class="pkp_button" title="{translate key="plugins.generic.dossierManager.contribution.up"}" aria-label="{translate key="plugins.generic.dossierManager.contribution.up"}">↑</button>{/if}
								{if !$smarty.foreach.contributions.last}<button type="submit" name="action" value="down" class="pkp_button" title="{translate key="plugins.generic.dossierManager.contribution.down"}" aria-label="{translate key="plugins.generic.dossierManager.contribution.down"}">↓</button>{/if}
								{if $row.open}
									{if $row.paused}
										<button type="submit" name="action" value="resume" class="pkp_button">{translate key="plugins.generic.dossierManager.contribution.resume"}</button>
									{else}
										<button type="submit" name="action" value="pause" class="pkp_button">{translate key="plugins.generic.dossierManager.contribution.pause"}</button>
									{/if}
								{/if}
								{if $row.status == 'dropped' || $row.status == 'declined'}
									<button type="submit" name="action" value="restore" class="pkp_button">{translate key="plugins.generic.dossierManager.contribution.restore"}</button>
								{elseif $row.canRemove}
									<button type="submit" name="action" value="remove" class="pkp_button pkp_button_offset" data-dm-confirm="{translate key="plugins.generic.dossierManager.contribution.removeConfirm"}">{translate key="common.remove"}</button>
								{elseif $row.status != 'published'}
									<button type="submit" name="action" value="drop" class="pkp_button pkp_button_offset" data-dm-confirm="{translate key="plugins.generic.dossierManager.contribution.dropConfirm"}">{translate key="plugins.generic.dossierManager.contribution.drop"}</button>
								{/if}
							</form>
							{if $row.canInvite && !$invitationBlocker}
								<a class="pkp_button" href="{url page=$dmPage op="compose" path=$dossier->id params=["contributionId" => $row.id]}">{if $row.dateInvited}{translate key="plugins.generic.dossierManager.contribution.resend"}{else}{translate key="plugins.generic.dossierManager.contribution.invite"}{/if}</a>
							{/if}
						</div>
						{if $row.link && $row.contributor && ($row.open || $row.status == 'planned')}
							<div class="dmLink">
								<label for="dmLink-{$row.id|escape}">{translate key="plugins.generic.dossierManager.contribution.link"}</label>
								<input type="text" readonly id="dmLink-{$row.id|escape}" value="{$row.link|escape}" />
								<button type="button" class="pkp_button" data-dm-copy="dmLink-{$row.id|escape}" data-dm-copied="{translate key="plugins.generic.dossierManager.contribution.copied"}">{translate key="plugins.generic.dossierManager.contribution.copy"}</button>
								<form method="post" action="{url page=$dmPage op="contributionAction"}" class="dmInline" data-dm-confirm="{translate key="plugins.generic.dossierManager.contribution.renewConfirm"}">
									{csrf}
									<input type="hidden" name="contributionId" value="{$row.id|escape}" />
									<button type="submit" name="action" value="renewLink" class="pkp_button">{translate key="plugins.generic.dossierManager.contribution.renew"}</button>
								</form>
							</div>
						{/if}
						<details class="dmEdit">
							<summary>{translate key="plugins.generic.dossierManager.contribution.edit"}</summary>
							<form method="post" action="{url page=$dmPage op="updateContribution"}" class="dmForm">
								{csrf}
								<input type="hidden" name="contributionId" value="{$row.id|escape}" />
								<div class="dmFieldRow">
									<div class="dmField dmField--wide">
										<label for="dmWt-{$row.id|escape}">{translate key="plugins.generic.dossierManager.contribution.workingTitle"}</label>
										<input type="text" id="dmWt-{$row.id|escape}" name="workingTitle" value="{$row.workingTitle|escape}" />
									</div>
									<div class="dmField">
										<label for="dmDl-{$row.id|escape}">{translate key="plugins.generic.dossierManager.contribution.individualDeadline"}</label>
										<input type="date" id="dmDl-{$row.id|escape}" name="deadline" value="{$row.ownDeadline|escape}" />
									</div>
								</div>
								<div class="dmField">
									<label for="dmNote-{$row.id|escape}">{translate key="plugins.generic.dossierManager.contribution.note"}</label>
									<input type="text" id="dmNote-{$row.id|escape}" name="note" value="{$row.note|escape}" />
								</div>
								{if !$row.submissionUrl}
									<p class="dmHint">{translate key="plugins.generic.dossierManager.contribution.changeContributor"}</p>
									{include file=$dmContributorFields idPrefix="dmC`$row.id`"}
								{/if}
								<button type="submit" class="pkp_button pkp_button_primary">{translate key="common.save"}</button>
							</form>
							{if $row.open}
								<form method="post" action="{url page=$dmPage op="contributionAction"}" class="dmForm dmDeclineForm" data-dm-confirm="{translate key="plugins.generic.dossierManager.contribution.markDeclinedConfirm"}">
									{csrf}
									<input type="hidden" name="contributionId" value="{$row.id|escape}" />
									<label for="dmReason-{$row.id|escape}">{translate key="plugins.generic.dossierManager.contribution.markDeclinedReason"}</label>
									<input type="text" id="dmReason-{$row.id|escape}" name="reason" />
									<button type="submit" name="action" value="markDeclined" class="pkp_button pkp_button_offset">{translate key="plugins.generic.dossierManager.contribution.markDeclined"}</button>
								</form>
							{/if}
						</details>
					</td>
				</tr>
				{/if}
			{/foreach}
			</tbody>
		</table>
	{/if}

	{if $canEdit && !$dossier->cancelled}
		<details class="dmAdd"{if !$rows} open{/if}>
			<summary class="pkp_button">{translate key="plugins.generic.dossierManager.contribution.add"}</summary>
			<form method="post" action="{url page=$dmPage op="addContribution"}" class="dmForm">
				{csrf}
				<input type="hidden" name="dossierId" value="{$dossier->id|escape}" />
				<div class="dmFieldRow">
					<div class="dmField dmField--wide">
						<label for="dmNewWt">{translate key="plugins.generic.dossierManager.contribution.workingTitle"}</label>
						<input type="text" id="dmNewWt" name="workingTitle" />
					</div>
					<div class="dmField">
						<label for="dmNewDl">{translate key="plugins.generic.dossierManager.contribution.individualDeadline"}</label>
						<input type="date" id="dmNewDl" name="deadline" />
					</div>
				</div>
				{include file=$dmContributorFields idPrefix="dmNew"}
				<div class="dmField">
					<label for="dmNewNote">{translate key="plugins.generic.dossierManager.contribution.note"}</label>
					<input type="text" id="dmNewNote" name="note" />
				</div>
				<button type="submit" class="pkp_button pkp_button_primary">{translate key="plugins.generic.dossierManager.contribution.add"}</button>
			</form>
		</details>
	{/if}

	{if $notifyMode}
		<form method="post" action="{url page=$dmPage op="notifyMode"}" class="dmForm dmNotifyMode">
			{csrf}
			<input type="hidden" name="dossierId" value="{$dossier->id|escape}" />
			<label for="dmNotifyMode">{translate key="plugins.generic.dossierManager.notifyMode"}</label>
			<select id="dmNotifyMode" name="mode">
				{foreach from=$notifyModes item=mode}
					<option value="{$mode|escape}"{if $mode == $notifyMode} selected{/if}>{translate key="plugins.generic.dossierManager.notifyMode.`$mode`"}</option>
				{/foreach}
			</select>
			<button type="submit" class="pkp_button">{translate key="common.save"}</button>
		</form>
	{/if}

	<details class="dmHistory">
		<summary><h2 class="dmInlineHeading">{translate key="plugins.generic.dossierManager.history"}</h2></summary>
		{if !$history}
			<p>{translate key="plugins.generic.dossierManager.history.empty"}</p>
		{else}
			<table class="dmTable">
				<tbody>
				{foreach from=$history item=entry}
					<tr>
						<td class="dmNoWrap">{$entry.date|date_format:$datetimeFormatShort}</td>
						<td class="dmNoWrap">{$entry.actor|escape}</td>
						<td>
							{if $entry.type == 'email'}
								<details>
									<summary>✉ {$entry.label|escape}: {$entry.subject|escape} → {$entry.recipients|escape}</summary>
									<div class="dmEmailBody">{$entry.body|strip_unsafe_html}</div>
								</details>
							{else}
								{$entry.label|escape}
							{/if}
						</td>
					</tr>
				{/foreach}
				</tbody>
			</table>
		{/if}
	</details>
</div>
{/block}
