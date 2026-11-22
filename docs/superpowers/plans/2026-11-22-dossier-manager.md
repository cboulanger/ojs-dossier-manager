# Dossier Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** First working version of the Dossier Manager plugin for OJS 3.5 as specified in issue #1.

**Architecture:** A lazy-loaded generic plugin with its own tables (Illuminate schema builder), plain PHP repository classes, Smarty backend pages (POST + redirect, no legacy grids), a public page for the personal submission link, hooks into submission creation/completion, mailables with editable templates, and a daily scheduled task. Pure decision logic (statuses, reminder timing) lives in side-effect-free classes so it can be unit tested.

**Tech Stack:** PHP 8.2, OJS 3.5.0-6, Smarty, Illuminate DB, PHPUnit 11 in the dev container (Podman).

**Spec:** `docs/specs/2026-11-22-dossier-manager-design.md`

## Global Constraints

- OJS 3.5, PHP ≥ 8.2. Plugin folder `dossierManager`, class `DossierManagerPlugin`, namespace `APP\plugins\generic\dossierManager`.
- Tables prefixed `dm_`. Schema migration idempotent, `SCHEMA_VERSION`, upgraded on load (Book Review Manager pattern).
- Locales: `en` and `de` only. Locale keys prefixed `plugins.generic.dossierManager.`; email keys `emails.dm*`, template keys `DM_*`.
- Defaults: reminder 7 days before deadline; overdue interval 7 days; max overdue reminders 4 (0 = unlimited); decline link on; emails on; dossier types Special issue, Thematic section, Review symposium, Forum.
- Opening any link changes nothing (GET shows, POST acts, CSRF on every POST).
- Editors-only target sections must work: the submission is moved into the target section and target issue **when the wizard is completed**, not at creation.

## Review Focus

1. Submission link opened while logged in as a different user → page explains which account the link belongs to; nothing is attached.
2. Contributor starts the submission, abandons the wizard, opens the link again → continues the existing incomplete submission instead of creating a second one.
3. Deadline changed (dossier or individual) after reminders were sent → reminder counters reset so the new deadline gets its own reminders.
4. Contributor declined / contribution dropped / reminders paused → no reminders are sent.
5. Target issue/section changed after contributions were submitted → unpublished, unscheduled publications move; scheduled/published ones stay.

---

### Task 1: Scaffold, schema, roles, dev container

**Files:** `version.xml`, `index.php`-less lazy plugin `DossierManagerPlugin.php`, `DossierManagerSchemaMigration.php`, `classes/Roles.php`, `locale/{en,de}/locale.po`, `dev/*` (adapted from Book Review Manager), `.gitattributes`.

**Produces:** tables `dm_dossiers` (localized `title`/`description` as JSON), `dm_dossier_editors(dossier_id,user_id,notify_mode)`, `dm_contributions`, `dm_digest`; `Roles::canManage()`, `Roles::isManager()`, `Roles::getGroupId()`; dev journal with users `dossiereditor`, `author`, `contributor2`, a future issue and an editors-only section "Symposium".

- [ ] Container builds, plugin installs and enables, tables exist (`SHOW TABLES LIKE 'dm_%'`).
- [ ] Commit.

### Task 2: Domain classes and pure logic (TDD)

**Files:** `classes/Dossier.php`, `classes/Contribution.php`, `classes/DossierRepository.php`, `classes/ContributionStatus.php`, `classes/DossierStatus.php`, `classes/ReminderPolicy.php`; tests `tests/unit/php/{ContributionStatusTest,DossierStatusTest,ReminderPolicyTest}.php`, `tests/run.sh`.

**Produces:**
- `ContributionStatus::derive(string $stored, ?array $submission): string` → one of `planned, invited, declined, dropped, inProgress, submitted, notAccepted, published`; `$submission` = `['progress' => string, 'status' => int]`.
- `DossierStatus::derive(bool $cancelled, bool $invited, bool $issuePublished, ?string $deadline, array $contributionStatuses, string $today): string` → `planning, invited, collecting, complete, published, cancelled`.
- `ReminderPolicy::due(array $c, string $today, int $daysBefore, int $interval, int $max): ?string` → `'before'|'overdue'|null`; `$c` keys `deadline, dateInvited, reminderSent, overdueCount, lastOverdue`.
- Tests pin: before-reminder sent once in the window and not if invited < 2 days ago; first overdue the day after the deadline, then every `interval` days; stops at `max` (0 = unlimited); `daysBefore = 0` disables the before-reminder.

### Task 3: Backend pages (dossiers, contributions, settings, menu)

**Files:** `pages/DossiersHandler.php`, `templates/{dossiers,dossierForm,dossier,settings}.tpl`, `classes/ContributorAccounts.php`, `classes/DossierLog.php`.

- My dossiers / All dossiers (managers), Create, Edit, View with contributions (add from user search or ad hoc, edit, reorder, individual deadline, pause, drop, mark declined, renew link, copy link), cancel/reopen, history, settings (managers), notification preference per editor.
- Target change moves unpublished, unscheduled publications (Review Focus 5).

### Task 4: Emails and notifications

**Files:** `mailables/{DmInvitation,DmReminder,DmOverdue,DmEditorNotification,DmEditorDigest}.php`, `emailTemplates.xml`, `locale/*/emails.po`, `classes/DossierMailer.php`, `classes/Notifier.php`, `templates/compose.tpl`.

- Invitation compose page (edit once, personalised per recipient: `recipientName`, `workingTitle`, `deadline`, `submissionUrl`, `declineUrl`); resend per contribution.
- `Notifier::notify(Dossier, string $event, array $params, ?User $actor)`: immediate events `submitted, declined, maxReminders`; others digest unless editor chose `immediate`/`digest`.

### Task 5: Submission link, wizard integration, lifecycle hooks

**Files:** `pages/DossierHandler.php` (`submit`, `start`, `decline`, `attach`, `detach`), `pages/MyContributionsHandler.php`, `templates/{link,decline,wizardSection,myContributions}.tpl`, plugin hooks `Submission::add`, `Submission::edit`, `Submission::updateStatus`, `Publication::publish`, `Template::SubmissionWizard::Section`, `TemplateManager::display`.

- Review Focus 1 and 2 handled on the link page.

### Task 6: Scheduled task

**Files:** `classes/DossierReminder.php`. Uses `ReminderPolicy::due`, sends reminders, notifies at max, sends digests. Review Focus 3/4: counters reset on deadline change (Task 3), declined/dropped/paused skipped.

### Task 7: End-to-end check in the dev container and docs

- Walk the whole flow in the browser (Playwright) and with the scheduler CLI; README with installation and usage.
