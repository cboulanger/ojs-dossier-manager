#!/bin/bash
# Run the plugin's tests.
#   tests/run.sh          unit tests (PHPUnit) in the dev container (dev/ojs-dev.sh up)
#   tests/run.sh smoke    end-to-end smoke test of the scheduled task and hooks in the container
# Arguments after the suite name go to PHPUnit, e.g. tests/run.sh unit --filter ReminderPolicyTest
set -euo pipefail
PODMAN="${PODMAN:-podman}"
CONTAINER="${DM_CONTAINER:-ojs-dossiermanager}"
PLUGIN=/var/www/html/plugins/generic/dossierManager

if ! "$PODMAN" container inspect "$CONTAINER" >/dev/null 2>&1; then
	echo "Container $CONTAINER is not running; start it with dev/ojs-dev.sh up" >&2
	exit 3
fi
suite="${1:-unit}"
shift || true
case "$suite" in
	unit) "$PODMAN" exec -w "$PLUGIN/tests/unit/php" "$CONTAINER" phpunit "$@" ;;
	smoke) "$PODMAN" exec -u www-data -w /var/www/html "$CONTAINER" php "$PLUGIN/tests/smoke/smoke.php" "$@" ;;
	*) echo "Unknown suite: $suite (unit, smoke)" >&2; exit 2 ;;
esac
