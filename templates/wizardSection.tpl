{**
 * plugins/generic/dossierManager/templates/wizardSection.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Section "Dossier" in the step "For the Editors" of the submission wizard:
 * the contribution this submission belongs to, or a choice of the user's open contributions.
 * $dossierWizard: see DossierManagerPlugin::addToSubmissionWizard()
 *}
<div class="dmWizard" v-pre>
	{if $dossierWizard.attached}
		{assign var=row value=$dossierWizard.attached}
		<p>{translate key="plugins.generic.dossierManager.wizard.attached" type=$row.dossierType|escape title=$row.dossierTitle|escape}</p>
		{if $row.workingTitle}<p>{translate key="plugins.generic.dossierManager.contribution.workingTitle"}: <em>{$row.workingTitle|escape}</em></p>{/if}
		{if $row.deadline}<p>{translate key="plugins.generic.dossierManager.deadline"}: {$row.deadline|date_format:$dateFormatLong}</p>{/if}
		<p class="dmHint">{translate key="plugins.generic.dossierManager.wizard.targetHint"}</p>
		<form method="post" action="{url page="dossier" op="detach"}">
			{csrf}
			<input type="hidden" name="submissionId" value="{$dossierWizard.submissionId|escape}" />
			<button type="submit" class="pkpButton">{translate key="plugins.generic.dossierManager.wizard.detach"}</button>
		</form>
	{else}
		<p>{translate key="plugins.generic.dossierManager.wizard.choose"}</p>
		<form method="post" action="{url page="dossier" op="attach"}">
			{csrf}
			<input type="hidden" name="submissionId" value="{$dossierWizard.submissionId|escape}" />
			{foreach from=$dossierWizard.choices item=row name=choices}
				<label style="display:block;margin-bottom:.5rem">
					<input type="radio" name="contributionId" value="{$row.contributionId|escape}"{if $smarty.foreach.choices.first} checked{/if} />
					{$row.dossierType|escape}: <strong>{$row.dossierTitle|escape}</strong>{if $row.workingTitle} – <em>{$row.workingTitle|escape}</em>{/if}
				</label>
			{/foreach}
			<button type="submit" class="pkpButton">{translate key="plugins.generic.dossierManager.wizard.attach"}</button>
		</form>
	{/if}
</div>
