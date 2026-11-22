<?php

/**
 * @file dev/devSetup.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Non-interactive setup of a local OJS test instance. Copied into the OJS
 * tools/ directory by the dev Containerfile; not used in production.
 *
 * Usage:
 *   php tools/devSetup.php install         Install OJS (database + config.inc.php)
 *   php tools/devSetup.php journal         Create the test journal and an author account
 *   php tools/devSetup.php enable-plugin   Enable dossierManager in all journals
 *   php tools/devSetup.php users           Test accounts, a future issue and an editors-only section
 *
 * Settings are read from environment variables (see Containerfile).
 */

require(dirname(__FILE__) . '/bootstrap.php');

use APP\core\Application;
use APP\facades\Repo;
use PKP\cliTool\InstallTool;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\userGroup\relationships\UserUserGroup;
use PKP\userGroup\UserGroup;

function devEnv(string $name, string $default): string
{
	$value = getenv($name);
	return ($value === false || $value === '') ? $default : $value;
}

/**
 * Runs the regular OJS installer with parameters from the environment instead of stdin.
 */
class DevInstallTool extends InstallTool
{
	public function readParams()
	{
		$this->params = [
			'locale' => 'en',
			'additionalLocales' => ['de'],
			'timeZone' => devEnv('OJS_TIMEZONE', 'Europe/Berlin'),
			'filesDir' => devEnv('OJS_FILES_DIR', '/var/www/files'),
			'adminUsername' => devEnv('OJS_ADMIN_USER', 'admin'),
			'adminPassword' => devEnv('OJS_ADMIN_PASSWORD', 'ojsadmin'),
			'adminPassword2' => devEnv('OJS_ADMIN_PASSWORD', 'ojsadmin'),
			'adminEmail' => devEnv('OJS_ADMIN_EMAIL', 'admin@example.org'),
			'databaseDriver' => 'mysqli',
			'databaseHost' => 'localhost',
			'databaseUsername' => devEnv('OJS_DB_USER', 'ojs'),
			'databasePassword' => devEnv('OJS_DB_PASSWORD', 'ojs'),
			'databaseName' => devEnv('OJS_DB_NAME', 'ojs'),
			'oaiRepositoryId' => 'ojs.localhost',
			'enableBeacon' => 0,
		];
		return true;
	}
}

class DevSetupTool extends \PKP\cliTool\CommandLineTool
{
	public function usage()
	{
		echo "Usage: php tools/devSetup.php install|journal|enable-plugin|users\n";
	}

	public function execute()
	{
		switch ($this->argv[0] ?? '') {
			case 'journal':
				$this->createJournal();
				$this->createAuthor();
				break;
			case 'enable-plugin':
				$this->enablePlugin();
				break;
			case 'users':
				$this->createWorkflowUsers();
				break;
			default:
				$this->usage();
				exit(1);
		}
	}

	/**
	 * Create the test journal; the admin user (registered by CommandLineTool) becomes its manager.
	 */
	protected function createJournal()
	{
		$path = devEnv('OJS_JOURNAL_PATH', 'test');
		$contextDao = Application::getContextDAO();
		if ($contextDao->getByPath($path)) {
			echo "Journal '{$path}' already exists.\n";
			return;
		}

		$context = $contextDao->newDataObject();
		$context->setData('urlPath', $path);
		$context->setData('name', ['en' => 'Test Journal', 'de' => 'Testzeitschrift']);
		$context->setData('acronym', ['en' => 'TJ', 'de' => 'TZ']);
		$context->setData('contactName', 'Journal Manager');
		$context->setData('contactEmail', devEnv('OJS_ADMIN_EMAIL', 'admin@example.org'));
		$context->setData('enabled', true);
		$context->setData('primaryLocale', 'en');
		$context->setData('supportedLocales', ['en', 'de']);
		$context->setData('supportedFormLocales', ['en', 'de']);
		$context->setData('supportedSubmissionLocales', ['en', 'de']);
		$context->setData('supportedAddedSubmissionLocales', ['en', 'de']);
		$context->setData('supportedSubmissionMetadataLocales', ['en', 'de']);
		$context->setData('country', 'DE');

		$context = app()->get('context')->add($context, Application::get()->getRequest());
		echo "Created journal '{$path}' (id {$context->getId()}).\n";
	}

	/**
	 * Create an author account in the test journal, for testing the author-side form.
	 */
	protected function createAuthor()
	{
		$username = devEnv('OJS_AUTHOR_USER', 'author');
		if (Repo::user()->getByUsername($username, true)) {
			echo "User '{$username}' already exists.\n";
			return;
		}
		$context = Application::getContextDAO()->getByPath(devEnv('OJS_JOURNAL_PATH', 'test'));

		$user = Repo::user()->newDataObject();
		$user->setUsername($username);
		$user->setEmail(devEnv('OJS_AUTHOR_EMAIL', 'author@example.org'));
		$user->setPassword(Validation::encryptCredentials($username, devEnv('OJS_AUTHOR_PASSWORD', 'ojsauthor')));
		$user->setGivenName('Test', 'en');
		$user->setFamilyName('Author', 'en');
		$user->setCountry('DE');
		$user->setAffiliation('Test University', 'en');
		$user->setDateRegistered(Core::getCurrentDate());
		$user->setDateValidated(Core::getCurrentDate());
		$user->setMustChangePassword(false);
		$userId = Repo::user()->add($user);

		$authorGroup = UserGroup::withContextIds([$context->getId()])
			->withRoleIds([Role::ROLE_ID_AUTHOR])
			->isDefault(true)
			->firstOrFail();
		UserUserGroup::create([
			'userId' => $userId,
			'userGroupId' => $authorGroup->userGroupId,
			'dateStart' => null,
			'dateEnd' => null,
			'masthead' => null,
		]);
		echo "Created author account '{$username}'.\n";
	}

	/**
	 * Accounts and data for testing dossiers: a dossier editor, a second contributor,
	 * a future issue and an editors-only section "Symposium". The plugin's group is created if needed.
	 */
	protected function createWorkflowUsers()
	{
		$context = Application::getContextDAO()->getByPath(devEnv('OJS_JOURNAL_PATH', 'test'));
		\PKP\plugins\PluginRegistry::loadCategory('generic', true, $context->getId());
		$plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'DossierManagerPlugin');
		$editorGroupId = \APP\plugins\generic\dossierManager\classes\Roles::getGroupId($plugin, $context->getId());
		$authorGroupId = UserGroup::withContextIds([$context->getId()])->withRoleIds([Role::ROLE_ID_AUTHOR])->isDefault(true)->firstOrFail()->userGroupId;
		$this->createUser($context, 'dossiereditor', 'ojsdossiereditor', 'Dora', 'Dossier', 'dossiereditor@example.org', $editorGroupId);
		$this->createUser($context, 'contributor2', 'ojscontributor2', 'Second', 'Contributor', 'contributor2@example.org', $authorGroupId);

		if (!Repo::issue()->getCollector()->filterByContextIds([$context->getId()])->filterByPublished(false)->getCount()) {
			$issue = Repo::issue()->newDataObject([
				'journalId' => $context->getId(),
				'volume' => 1,
				'number' => '2',
				'year' => (int) date('Y') + 1,
				'showVolume' => true,
				'showNumber' => true,
				'showYear' => true,
				'showTitle' => false,
				'published' => false,
				'accessStatus' => \APP\issue\Issue::ISSUE_ACCESS_OPEN,
			]);
			Repo::issue()->add($issue);
			echo "Created a future issue.\n";
		}
		$sections = Repo::section()->getCollector()->filterByContextIds([$context->getId()])->getMany();
		$hasSymposium = false;
		foreach ($sections as $section) {
			$hasSymposium = $hasSymposium || $section->getLocalizedTitle() === 'Symposium';
		}
		if (!$hasSymposium) {
			$section = Repo::section()->newDataObject([
				'contextId' => $context->getId(),
				'title' => ['en' => 'Symposium', 'de' => 'Symposium'],
				'abbrev' => ['en' => 'SYM', 'de' => 'SYM'],
				'editorRestricted' => true,
				'metaIndexed' => true,
				'metaReviewed' => true,
				'abstractsNotRequired' => false,
				'hideTitle' => false,
				'hideAuthor' => false,
				'isInactive' => false,
				'wordCount' => 0,
				'seq' => 2,
			]);
			Repo::section()->add($section);
			echo "Created the editors-only section 'Symposium'.\n";
		}
	}

	protected function createUser($context, string $username, string $password, string $given, string $family, string $email, int $userGroupId,
		string $affiliation = 'Test University', string $address = "Test University\nExample Street 1\n12345 Example City")
	{
		if (Repo::user()->getByUsername($username, true)) {
			echo "User '{$username}' already exists.\n";
			return;
		}
		$user = Repo::user()->newDataObject();
		$user->setUsername($username);
		$user->setEmail($email);
		$user->setPassword(Validation::encryptCredentials($username, $password));
		$user->setGivenName($given, 'en');
		$user->setFamilyName($family, 'en');
		$user->setCountry('DE');
		$user->setAffiliation($affiliation, 'en');
		$user->setMailingAddress($address);
		$user->setDateRegistered(Core::getCurrentDate());
		$user->setDateValidated(Core::getCurrentDate());
		$user->setMustChangePassword(false);
		$userId = Repo::user()->add($user);
		UserUserGroup::create(['userId' => $userId, 'userGroupId' => $userGroupId, 'dateStart' => null, 'dateEnd' => null, 'masthead' => null]);
		echo "Created account '{$username}' (password '{$password}').\n";
	}

	/**
	 * Enable dossierManager in every journal (the schema is installed by installPluginVersion.php).
	 */
	protected function enablePlugin()
	{
		$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
		$contexts = Application::getContextDAO()->getAll();
		while ($context = $contexts->next()) {
			$pluginSettingsDao->updateSetting($context->getId(), 'dossiermanagerplugin', 'enabled', true, 'bool');
			echo "Enabled dossierManager in journal '{$context->getPath()}'.\n";
		}
	}
}

if (($argv[1] ?? '') === 'install') {
	(new DevInstallTool($argv))->execute();
} else {
	(new DevSetupTool($argv))->execute();
}
