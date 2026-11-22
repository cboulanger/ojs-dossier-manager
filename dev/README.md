# Local test environment

A single Podman (or Docker) container with Apache, PHP 8.2, MariaDB and OJS 3.5, preinstalled with a test journal. The plugin directory is mounted into the container, so changes to PHP files, templates and locale files take effect without a rebuild. Adapted from [Book Review Manager](https://github.com/cboulanger/ojs-book-review-manager).

For local testing only: the passwords are fixed, emails are only written to the log, and the container listens on 127.0.0.1 only.

```sh
dev/ojs-dev.sh up      # builds the image on first use (takes a while), then starts OJS
dev/ojs-dev.sh logs    # Apache/PHP log; sent emails appear here, with their links
dev/ojs-dev.sh shell   # shell in /var/www/html
dev/ojs-dev.sh down    # remove the container and all test data
dev/ojs-dev.sh reset   # start again from a fresh install
dev/ojs-dev.sh build   # rebuild the image (after changing files in dev/)
```

OJS runs at <http://localhost:8081/index.php/test> (set `OJS_PORT` for another port).

| Account | Password | Roles |
| --- | --- | --- |
| `admin` | `ojsadmin` | Site admin, journal manager |
| `dossiereditor` | `ojsdossiereditor` | Dossier editor (plugin role, base role Section editor) |
| `author` | `ojsauthor` | Author |
| `contributor2` | `ojscontributor2` | Author |

On first start, the container installs the plugin, enables it, and creates the accounts above, a future issue (Vol. 1 No. 2) and an editors-only section "Symposium".

Run the daily task by hand (as `www-data`, otherwise Apache can't write the cache files afterwards):

```sh
dev/ojs-dev.sh shell
runuser -u www-data -- php lib/pkp/tools/scheduler.php test --name="APP\plugins\generic\dossierManager\classes\DossierReminder"
```

## Files

- `Containerfile`: the image (OJS version in `ARG OJS_VERSION`)
- `build-install.sh`: runs at build time; creates the database, installs OJS, creates the journal and the author
- `devSetup.php`: OJS command line tool used by the scripts (install, journal, enable-plugin, users)
- `entrypoint.sh`: container start; sets `base_url`, installs and enables the plugin, runs Apache
