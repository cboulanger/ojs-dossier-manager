{**
 * plugins/generic/dossierManager/templates/decline.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Decline an invitation to contribute (confirmation page, then the result).
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.dossierManager.decline.title"}

<div class="page page_dossier_decline">
	<h1>{translate key="plugins.generic.dossierManager.decline.title"}</h1>
	{if $problem}
		<p>{translate key=$problem}</p>
	{elseif $done}
		<p>{translate key="plugins.generic.dossierManager.decline.done"}</p>
	{else}
		{include file=$dmIntro}
		<form method="post" action="{url page=$dmPage op="decline" path=$key}" class="cmp_form">
			{csrf}
			<p>{translate key="plugins.generic.dossierManager.decline.question"}</p>
			<div class="fields">
				<label for="dmReason">{translate key="plugins.generic.dossierManager.decline.reason"}</label>
				<textarea id="dmReason" name="reason" rows="4" style="width:100%"></textarea>
			</div>
			<p>
				<button type="submit" class="cmp_button">{translate key="plugins.generic.dossierManager.decline.confirm"}</button>
				<a href="{url page=$dmPage op="submit" path=$key}">{translate key="common.cancel"}</a>
			</p>
		</form>
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
