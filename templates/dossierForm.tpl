{**
 * plugins/generic/dossierManager/templates/dossierForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Create or edit a dossier.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
<div class="app__contentPanel dmPanel" v-pre>
	{if $errors}
		<div class="dmNotice dmNotice--error">{translate key="plugins.generic.dossierManager.form.errors"}</div>
	{/if}
	<form method="post" action="{url page=$dmPage op="save"}" class="dmForm">
		{csrf}
		<input type="hidden" name="dossierId" value="{$dossier->id|escape}" />

		<div class="dmField">
			<label for="dmType">{translate key="plugins.generic.dossierManager.type"}</label>
			<select id="dmType" name="type">
				{foreach from=$types item=type}
					<option value="{$type|escape}"{if $type == $dossier->type} selected{/if}>{$type|escape}</option>
				{/foreach}
			</select>
		</div>

		{foreach from=$formLocales key=locale item=localeName}
			<div class="dmField">
				<label for="dmTitle-{$locale|escape}">{translate key="plugins.generic.dossierManager.title"} ({$localeName|escape}){if $locale == $primaryLocale} *{/if}</label>
				<input type="text" id="dmTitle-{$locale|escape}" name="title[{$locale|escape}]" value="{$dossier->title[$locale]|default:''|escape}" />
				{if $locale == $primaryLocale && $errors.title}<div class="dmError">{$errors.title|escape}</div>{/if}
			</div>
		{/foreach}
		{foreach from=$formLocales key=locale item=localeName}
			<div class="dmField">
				<label for="dmDescription-{$locale|escape}">{translate key="plugins.generic.dossierManager.dossierDescription"} ({$localeName|escape})</label>
				<textarea id="dmDescription-{$locale|escape}" name="description[{$locale|escape}]" rows="12" class="dmRich">{$dossier->description[$locale]|default:''|escape}</textarea>
			</div>
		{/foreach}
		<p class="dmHint">{translate key="plugins.generic.dossierManager.form.descriptionHint"}</p>

		<div class="dmFieldRow">
			<div class="dmField">
				<label for="dmIssue">{translate key="plugins.generic.dossierManager.targetIssue"}</label>
				<select id="dmIssue" name="issueId">
					<option value="">{translate key="plugins.generic.dossierManager.form.notYet"}</option>
					{foreach from=$issues key=issueId item=issueTitle}
						<option value="{$issueId|escape}"{if $issueId == $dossier->issueId} selected{/if}>{$issueTitle|escape}</option>
					{/foreach}
				</select>
				{if $errors.issueId}<div class="dmError">{$errors.issueId|escape}</div>{/if}
			</div>
			<div class="dmField">
				<label for="dmSection">{translate key="plugins.generic.dossierManager.targetSection"}</label>
				<select id="dmSection" name="sectionId">
					<option value="">{translate key="plugins.generic.dossierManager.form.notYet"}</option>
					{foreach from=$sections key=sectionId item=sectionTitle}
						<option value="{$sectionId|escape}"{if $sectionId == $dossier->sectionId} selected{/if}>{$sectionTitle|escape}</option>
					{/foreach}
				</select>
				{if $errors.sectionId}<div class="dmError">{$errors.sectionId|escape}</div>{/if}
			</div>
			<div class="dmField">
				<label for="dmDeadline">{translate key="plugins.generic.dossierManager.deadline"}</label>
				<input type="date" id="dmDeadline" name="deadline" value="{$dossier->deadline|escape}" />
				{if $errors.deadline}<div class="dmError">{$errors.deadline|escape}</div>{/if}
			</div>
		</div>
		<p class="dmHint">{translate key="plugins.generic.dossierManager.form.targetHint"}</p>

		<div class="dmField">
			<label>{translate key="plugins.generic.dossierManager.editors"} *</label>
			<div class="dmChecklist">
				{foreach from=$editorCandidates item=candidate}
					<label><input type="checkbox" name="editors[]" value="{$candidate.id|escape}"{if $candidate.checked} checked{/if} /> {$candidate.name|escape}</label>
				{/foreach}
			</div>
			{if $errors.editors}<div class="dmError">{$errors.editors|escape}</div>{/if}
			<p class="dmHint">{translate key="plugins.generic.dossierManager.form.editorsHint"}</p>
		</div>

		<fieldset class="dmFieldset">
			<legend>{translate key="plugins.generic.dossierManager.guestEditors"}</legend>
			<p class="dmHint">{translate key="plugins.generic.dossierManager.form.guestEditorsHint"}</p>
			{if $guestEditors}
				<div class="dmChecklist">
					{foreach from=$guestEditors key=guestId item=guestName}
						<label><input type="checkbox" name="guestEditors[]" value="{$guestId|escape}" checked /> {$guestName|escape}</label>
					{/foreach}
				</div>
				<p class="dmHint">{translate key="plugins.generic.dossierManager.form.guestEditorsRemoveHint"}</p>
			{/if}
			<p><strong>{translate key="plugins.generic.dossierManager.form.addGuestEditor"}</strong></p>
			{include file=$dmContributorFields idPrefix="dmGuest" newPersonHintKey="plugins.generic.dossierManager.form.newGuestHint"}
			{if $errors.guestEditors}<div class="dmError">{$errors.guestEditors|escape}</div>{/if}
			<label><input type="checkbox" name="inviteGuests" value="1"{if $inviteGuests} checked{/if} /> {translate key="plugins.generic.dossierManager.form.inviteGuests"}</label>
		</fieldset>

		<div class="dmField">
			<label for="dmGuidelines">{translate key="plugins.generic.dossierManager.guidelinesUrl"}</label>
			<input type="url" id="dmGuidelines" name="guidelinesUrl" value="{$dossier->guidelinesUrl|escape}" placeholder="{$defaultGuidelinesUrl|escape}" />
			{if $errors.guidelinesUrl}<div class="dmError">{$errors.guidelinesUrl|escape}</div>{/if}
			<p class="dmHint">{translate key="plugins.generic.dossierManager.form.guidelinesHint"}</p>
		</div>

		<div class="dmField">
			<label><input type="checkbox" name="showContributors" value="1"{if $dossier->showContributors} checked{/if} /> {translate key="plugins.generic.dossierManager.showContributors"}</label>
		</div>

		<fieldset class="dmFieldset">
			<legend>{translate key="plugins.generic.dossierManager.form.reminders"}</legend>
			<p class="dmHint">{translate key="plugins.generic.dossierManager.form.remindersHint" days=$journalReminders.days interval=$journalReminders.interval max=$journalReminders.max}</p>
			<div class="dmFieldRow">
				<div class="dmField">
					<label for="dmReminderDays">{translate key="plugins.generic.dossierManager.settings.reminderDays"}</label>
					<input type="number" min="0" id="dmReminderDays" name="reminderDays" value="{$dossier->reminderDays|escape}" placeholder="{$journalReminders.days|escape}" />
					{if $errors.reminderDays}<div class="dmError">{$errors.reminderDays|escape}</div>{/if}
				</div>
				<div class="dmField">
					<label for="dmOverdueInterval">{translate key="plugins.generic.dossierManager.settings.overdueInterval"}</label>
					<input type="number" min="0" id="dmOverdueInterval" name="overdueInterval" value="{$dossier->overdueInterval|escape}" placeholder="{$journalReminders.interval|escape}" />
					{if $errors.overdueInterval}<div class="dmError">{$errors.overdueInterval|escape}</div>{/if}
				</div>
				<div class="dmField">
					<label for="dmOverdueMax">{translate key="plugins.generic.dossierManager.settings.overdueMax"}</label>
					<input type="number" min="0" id="dmOverdueMax" name="overdueMax" value="{$dossier->overdueMax|escape}" placeholder="{$journalReminders.max|escape}" />
					{if $errors.overdueMax}<div class="dmError">{$errors.overdueMax|escape}</div>{/if}
				</div>
			</div>
		</fieldset>

		<div class="dmField">
			<label for="dmNotes">{translate key="plugins.generic.dossierManager.notes"}</label>
			<textarea id="dmNotes" name="notes" rows="3">{$dossier->notes|escape}</textarea>
			<p class="dmHint">{translate key="plugins.generic.dossierManager.form.notesHint"}</p>
		</div>

		<div class="dmButtons">
			<button type="submit" class="pkp_button pkp_button_primary">{translate key="common.save"}</button>
			{if $dossier->id}
				<a href="{url page=$dmPage op="view" path=$dossier->id}">{translate key="common.cancel"}</a>
			{else}
				<a href="{url page=$dmPage}">{translate key="common.cancel"}</a>
			{/if}
		</div>
	</form>
</div>
{/block}
