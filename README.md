# Dossier Manager for OJS 3.5

An OJS plugin for planning **dossiers**: groups of commissioned articles that are published together, such as special issues, thematic sections, review symposia or forums.

Editors plan a dossier with its target issue and section, submission deadline and planned contributions, invite the contributors by email, and track the submissions. Each contributor gets a personal submission link that starts a normal OJS submission attached to their planned contribution. Contributors are reminded before the deadline and at regular intervals after it.

The specification is in [issue #1](https://github.com/cboulanger/ojs-dossier-manager/issues/1) and in [docs/specs](docs/specs/2026-11-22-dossier-manager-design.md). The plugin follows the patterns of [Book Review Manager](https://github.com/cboulanger/ojs-book-review-manager).

## What it does

- **Dossiers** with a type (configurable list), multilingual title and description, target issue and section, submission deadline, one or more responsible editors, a link to the submission guidelines and internal notes.
- **Planned contributions** with a working title, a contributor (from the journal's users, or created ad hoc from last name, first name and email), an optional individual deadline and a note. They can be reordered, dropped and restored.
- **Invitations**: one editable email for all contributors, personalised per recipient. It contains the dossier, the list of planned contributions, the guidelines, the deadline and the personal submission link. Invitations can be resent at any time.
- **Submission links**: the responsible editors can copy and renew them. The link doesn't log anyone in: contributors log in (or set a password), and the link then opens OJS's normal submission wizard. The new submission is attached to the contribution. Contributors who started without the link can choose their contribution in the wizard (step *For the Editors*).
- **Editor assignment**: when a contribution is submitted, the responsible editors are assigned to it as editors (in their Dossier editor, section editor or manager role), so they can open it in the editorial workflow. Editors who become responsible later are assigned to the contributions already submitted. OJS's own assignment of the section's editors happens as usual.
- **Target issue and section**: when the submission is completed, it is put into the dossier's section (also an editors-only section) and assigned to the target issue. It is published only when the editors schedule it. If the target changes, unscheduled submissions move with it.
- **Reminders** (daily task): N days before the deadline (default 7), then every M days after it (default 7), at most a maximum number of times (default 4). Then the editors are notified. Reminders can be paused per contribution; a changed deadline starts them again.
- **Notifications** to the responsible editors: submissions, declines and exhausted reminders immediately; other changes in a daily summary. Each editor can change this per dossier.
- **Declining**: contributors can decline with a link (can be switched off); editors can record a decline.
- **Status tracking**: the dossier status (planning, invited, deadline passed, complete, published, cancelled) and each contribution's status, including the editorial stage of its submission.
- **History** of every step and every email.
- **Backend menu** "Dossiers" (*My dossiers*, *Create new dossier*, *Configuration*) and, for contributors, "My contributions".
- **Role** "Dossier editor" (base role Section editor), for example for guest editors: they can create dossiers and run the ones they are responsible for.
- **Editable emails** under *Settings › Emails*, in English and German.

## Installation

Requirements: OJS 3.5, PHP 8.2 or later.

1. Clone or unpack the plugin into `plugins/generic/dossierManager` (the folder name matters):

   ```sh
   cd /path/to/ojs/plugins/generic
   git clone https://github.com/cboulanger/ojs-dossier-manager.git dossierManager
   ```

2. Register it from the OJS folder: `php lib/pkp/tools/installPluginVersion.php plugins/generic/dossierManager/version.xml`
3. Enable *Dossier Manager* under *Website Settings › Plugins › Generic Plugins*. This creates the role "Dossier editor" and installs the email templates.
4. Make sure OJS runs its scheduled tasks, otherwise no reminders are sent.

The plugin upgrades its database tables when it loads.

## For developers

The [`dev/`](dev/) folder has a Podman (or Docker) setup that runs OJS 3.5 with a test journal on <http://localhost:8081/index.php/test>; see [dev/README.md](dev/README.md).

```sh
dev/ojs-dev.sh up        # build (first time) and start
tests/run.sh             # PHPUnit unit tests in the container
tests/run.sh smoke       # end-to-end smoke test against the container's database
```

Releases are built by GitHub Actions from an annotated tag (`v<version>`) and can be submitted to the PKP Plugin Gallery; see [dev/README.md](dev/README.md#releases).

| Path | Contents |
| --- | --- |
| `DossierManagerPlugin.php` | Hooks: menu, pages, submission lifecycle, submission wizard |
| `DossierManagerSchemaMigration.php` | Tables (`dm_*`). Raise `SCHEMA_VERSION` when changing it |
| `classes/DossierRepository.php` | Storage and derived statuses |
| `classes/ContributionStatus.php`, `DossierStatus.php`, `ReminderPolicy.php` | Pure decision logic (unit tested) |
| `classes/DossierWorkflow.php` | Invitations, declines, attaching and moving submissions |
| `classes/DossierMailer.php`, `Notifier.php`, `mailables/` | Emails and notifications |
| `classes/DossierReminder.php` | Daily task |
| `classes/DossierLog.php` | History (OJS event and email logs) |
| `pages/` | Backend pages, the public link pages, "My contributions" |

## License

GNU General Public License v3. See [LICENSE](LICENSE).
