{**
 * plugins/generic/dossierManager/templates/wizardReview.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The dossier in the review step of the submission wizard.
 *}
{assign var=row value=$dossierWizard.attached}
<div class="submissionWizard__reviewPanel">
	<div class="submissionWizard__reviewPanel__header">
		<h3 id="review-plugin-dossier-manager">{translate key="plugins.generic.dossierManager.wizard.name"}</h3>
	</div>
	<div class="submissionWizard__reviewPanel__body" v-pre>
		<div class="submissionWizard__reviewPanel__item">
			<div class="submissionWizard__reviewPanel__item__value">
				{translate key="plugins.generic.dossierManager.wizard.attached" type=$row.dossierType|escape title=$row.dossierTitle|escape}
			</div>
		</div>
	</div>
</div>
