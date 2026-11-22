{**
 * plugins/generic/dossierManager/templates/settings.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The plugin's journal settings (managers only).
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
<div class="app__contentPanel dmPanel" v-pre>
	<form method="post" action="{url page=$dmPage op="saveSettings"}" class="dmForm">
		{csrf}
		<div class="dmField">
			<label for="dmTypes">{translate key="plugins.generic.dossierManager.settings.types"}</label>
			<textarea id="dmTypes" name="dossierTypes" rows="6">{$values.dossierTypes|escape}</textarea>
			<p class="dmHint">{translate key="plugins.generic.dossierManager.settings.typesHint"}</p>
		</div>
		<fieldset class="dmFieldset">
			<legend>{translate key="plugins.generic.dossierManager.form.reminders"}</legend>
			<div class="dmFieldRow">
				<div class="dmField">
					<label for="dmReminderDays">{translate key="plugins.generic.dossierManager.settings.reminderDays"}</label>
					<input type="number" min="0" id="dmReminderDays" name="reminderDays" value="{$values.reminderDays|escape}" />
				</div>
				<div class="dmField">
					<label for="dmOverdueInterval">{translate key="plugins.generic.dossierManager.settings.overdueInterval"}</label>
					<input type="number" min="0" id="dmOverdueInterval" name="overdueInterval" value="{$values.overdueInterval|escape}" />
				</div>
				<div class="dmField">
					<label for="dmOverdueMax">{translate key="plugins.generic.dossierManager.settings.overdueMax"}</label>
					<input type="number" min="0" id="dmOverdueMax" name="overdueMax" value="{$values.overdueMax|escape}" />
				</div>
			</div>
			<p class="dmHint">{translate key="plugins.generic.dossierManager.settings.remindersHint"}</p>
		</fieldset>
		<div class="dmField">
			<label><input type="checkbox" name="allowDecline" value="1"{if $values.allowDecline} checked{/if} /> {translate key="plugins.generic.dossierManager.settings.allowDecline"}</label>
		</div>
		<div class="dmField">
			<label><input type="checkbox" name="suppressEmails" value="1"{if $values.suppressEmails} checked{/if} /> {translate key="plugins.generic.dossierManager.settings.suppressEmails"}</label>
			<p class="dmHint">{translate key="plugins.generic.dossierManager.settings.suppressEmailsHint"}</p>
		</div>
		<p class="dmHint">{translate key="plugins.generic.dossierManager.settings.roleHint" group=$groupName|escape}</p>
		<p class="dmHint">{translate key="plugins.generic.dossierManager.settings.emailsHint"}</p>
		<button type="submit" class="pkp_button pkp_button_primary">{translate key="common.save"}</button>
	</form>
</div>
{/block}
