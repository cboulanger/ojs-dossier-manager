{**
 * plugins/generic/dossierManager/templates/partials/contributorFields.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Choose a contributor from the journal's users or enter a new person.
 * $idPrefix: unique prefix for element IDs
 *}
<div class="dmUserSearch" data-url="{$usersUrl|escape}">
	<label for="{$idPrefix}-search">{translate key="plugins.generic.dossierManager.contribution.searchUser"}</label>
	<input type="search" id="{$idPrefix}-search" class="dmUserSearch__input" autocomplete="off" placeholder="{translate key="plugins.generic.dossierManager.contribution.searchPlaceholder"}" />
	<input type="hidden" name="userId" class="dmUserSearch__value" value="" />
	<ul class="dmUserSearch__results" role="listbox"></ul>
	<div class="dmUserSearch__selected" aria-live="polite"></div>
</div>
<details class="dmNewPerson">
	<summary>{translate key="plugins.generic.dossierManager.contribution.newPerson"}</summary>
	<div class="dmFieldRow">
		<div class="dmField">
			<label for="{$idPrefix}-family">{translate key="user.familyName"}</label>
			<input type="text" id="{$idPrefix}-family" name="familyName" />
		</div>
		<div class="dmField">
			<label for="{$idPrefix}-given">{translate key="user.givenName"}</label>
			<input type="text" id="{$idPrefix}-given" name="givenName" />
		</div>
		<div class="dmField">
			<label for="{$idPrefix}-email">{translate key="user.email"}</label>
			<input type="email" id="{$idPrefix}-email" name="email" />
		</div>
	</div>
	<p class="dmHint">{translate key="plugins.generic.dossierManager.contribution.newPersonHint"}</p>
</details>
