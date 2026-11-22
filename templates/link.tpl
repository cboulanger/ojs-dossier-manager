{**
 * plugins/generic/dossierManager/templates/link.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The personal submission link of a contribution. Opening it changes nothing.
 * $linkState: login | otherUser | start | continue | submitted
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.dossierManager.link.title"}

<div class="page page_dossier_link">
	<h1>{translate key="plugins.generic.dossierManager.link.title"}</h1>
	{if $problem}
		<p>{translate key=$problem}</p>
	{else}
		{include file=$dmIntro}

		{if $linkState == 'submitted'}
			<p>{translate key="plugins.generic.dossierManager.link.submitted"}</p>
			{if $submissionsUrl}<p><a class="cmp_button" href="{$submissionsUrl|escape}">{translate key="plugins.generic.dossierManager.myContributions.submissions"}</a></p>{/if}
		{elseif $linkState == 'login'}
			<p>{translate key="plugins.generic.dossierManager.link.loginNeeded" name=$contributorName|escape email=$contributorEmail|escape}</p>
			<p><a class="cmp_button" href="{$loginUrl|escape}">{translate key="user.login"}</a></p>
			<p>{translate key="plugins.generic.dossierManager.link.setPassword"} <a href="{$lostPasswordUrl|escape}">{translate key="plugins.generic.dossierManager.link.setPasswordLink"}</a></p>
		{elseif $linkState == 'otherUser'}
			<p>{translate key="plugins.generic.dossierManager.link.otherUser" name=$contributorName|escape current=$currentUserName|escape}</p>
			<p><a class="cmp_button" href="{$logoutUrl|escape}">{translate key="user.logOut"}</a></p>
		{elseif $linkState == 'continue'}
			<p>{translate key="plugins.generic.dossierManager.link.inProgress"}</p>
			<p><a class="cmp_button" href="{$continueUrl|escape}">{translate key="plugins.generic.dossierManager.link.continue"}</a></p>
		{else}
			<p>{translate key="plugins.generic.dossierManager.link.startHint"}</p>
			<form method="post" action="{url page=$dmPage op="start"}">
				{csrf}
				<input type="hidden" name="key" value="{$key|escape}" />
				<button type="submit" class="cmp_button">{translate key="plugins.generic.dossierManager.link.start"}</button>
			</form>
		{/if}

		{if $allowDecline && $linkState != 'submitted'}
			<p class="dmDeclineHint"><a href="{url page=$dmPage op="decline" path=$key}">{translate key="plugins.generic.dossierManager.link.declineLink"}</a></p>
		{/if}
	{/if}
</div>

{include file="frontend/components/footer.tpl"}
