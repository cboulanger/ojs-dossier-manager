{**
 * plugins/generic/dossierManager/templates/compose.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The invitation email, edited once and personalised per contributor when sent.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
<div class="app__contentPanel dmPanel" v-pre>
	<p><a href="{url page=$dmPage op="view" path=$dossier->id}">← {$dossierTitle|escape}</a></p>
	{if $emailsSuppressed}
		<div class="dmNotice dmNotice--warning">{translate key="plugins.generic.dossierManager.invite.suppressed"}</div>
	{/if}
	<form method="post" action="{url page=$dmPage op="sendInvitations"}" class="dmForm">
		{csrf}
		<input type="hidden" name="dossierId" value="{$dossier->id|escape}" />
		<fieldset class="dmFieldset">
			<legend>{translate key="plugins.generic.dossierManager.invite.recipients"}</legend>
			{foreach from=$recipients item=recipient}
				<label class="dmBlock">
					<input type="checkbox" name="contributionIds[]" value="{$recipient.id|escape}" checked />
					{$recipient.name|escape} &lt;{$recipient.email|escape}&gt;{if $recipient.workingTitle}: <em>{$recipient.workingTitle|escape}</em>{/if}
					{if $recipient.invited}<span class="dmHint">({translate key="plugins.generic.dossierManager.invite.again"})</span>{/if}
				</label>
			{/foreach}
		</fieldset>
		<div class="dmField">
			<label for="dmSubject">{translate key="email.subject"}</label>
			<input type="text" id="dmSubject" name="subject" value="{$subject|escape}" />
		</div>
		<div class="dmField">
			<label for="dmBody">{translate key="email.body"}</label>
			<textarea id="dmBody" name="body" rows="22" class="dmRich">{$body|escape}</textarea>
			<p class="dmHint">{translate key="plugins.generic.dossierManager.invite.variablesHint"}
				{foreach from=$lateVariables item=variable name=vars}<code>{literal}{${/literal}{$variable|escape}{literal}}{/literal}</code>{if !$smarty.foreach.vars.last}, {/if}{/foreach}
			</p>
		</div>
		<div class="dmButtons">
			<button type="submit" class="pkp_button pkp_button_primary">{translate key="plugins.generic.dossierManager.invite.submit"}</button>
			<a href="{url page=$dmPage op="view" path=$dossier->id}">{translate key="common.cancel"}</a>
		</div>
	</form>
</div>
{/block}
