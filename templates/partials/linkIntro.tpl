{**
 * plugins/generic/dossierManager/templates/partials/linkIntro.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The dossier and the contribution on the link pages.
 *}
<div class="dmLinkIntro">
	<p class="dmType">{$dossierType|escape}</p>
	<h2>{$dossierTitle|escape}</h2>
	{if $dossierDescription}<div class="dmDescription">{$dossierDescription|strip_unsafe_html}</div>{/if}
	<dl>
		{if $workingTitle}<dt>{translate key="plugins.generic.dossierManager.contribution.workingTitle"}</dt><dd>{$workingTitle|escape}</dd>{/if}
		{if $issueTitle}<dt>{translate key="plugins.generic.dossierManager.targetIssue"}</dt><dd>{$issueTitle|escape}</dd>{/if}
		{if $deadline}<dt>{translate key="plugins.generic.dossierManager.deadline"}</dt><dd><strong>{$deadline|escape}</strong></dd>{/if}
		<dt>{translate key="plugins.generic.dossierManager.guidelinesUrl"}</dt><dd><a href="{$guidelinesUrl|escape}">{$guidelinesUrl|escape}</a></dd>
	</dl>
</div>
