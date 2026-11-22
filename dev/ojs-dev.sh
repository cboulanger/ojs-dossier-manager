#!/bin/bash
# Manage the local OJS 3.5 test container with this plugin bind-mounted.
#
#   dev/ojs-dev.sh build    Build the image (OJS + test journal preinstalled)
#   dev/ojs-dev.sh up       Start the container (builds the image if missing)
#   dev/ojs-dev.sh down     Stop and remove the container (all test data is lost)
#   dev/ojs-dev.sh reset    down + up: fresh OJS instance
#   dev/ojs-dev.sh logs     Follow Apache/PHP logs
#   dev/ojs-dev.sh shell    Open a shell in /var/www/html
#
# Environment: OJS_PORT (default 8081), PODMAN (default podman; docker works too)
set -euo pipefail

DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$DEV_DIR")"
IMAGE=localhost/ojs-dossiermanager:3.5
CONTAINER=ojs-dossiermanager
PORT="${OJS_PORT:-8081}"
PODMAN="${PODMAN:-podman}"

build() {
	"$PODMAN" build -t "$IMAGE" -f "$DEV_DIR/Containerfile" "$DEV_DIR"
}

up() {
	if ! "$PODMAN" image exists "$IMAGE" 2>/dev/null && ! "$PODMAN" image inspect "$IMAGE" >/dev/null 2>&1; then
		build
	fi
	if "$PODMAN" container inspect "$CONTAINER" >/dev/null 2>&1; then
		"$PODMAN" start "$CONTAINER" >/dev/null
	else
		"$PODMAN" run -d --name "$CONTAINER" \
			-p "127.0.0.1:${PORT}:80" \
			-e OJS_BASE_URL="http://localhost:${PORT}" \
			-v "$PLUGIN_DIR:/var/www/html/plugins/generic/dossierManager:ro" \
			"$IMAGE" >/dev/null
	fi
	echo "Waiting for OJS..."
	for _ in $(seq 1 60); do
		if curl -sfL -o /dev/null "http://localhost:${PORT}/index.php/test"; then
			cat <<EOF
OJS is running at http://localhost:${PORT}/index.php/test
  Admin/journal manager: admin / ojsadmin
  Dossier editor:        dossiereditor / ojsdossiereditor
  Contributors:          author / ojsauthor, contributor2 / ojscontributor2
Plugin source mounted from $PLUGIN_DIR (edits are live).
EOF
			return 0
		fi
		sleep 2
	done
	echo "OJS did not come up; see: $0 logs" >&2
	exit 1
}

down() {
	"$PODMAN" rm -f "$CONTAINER" >/dev/null 2>&1 || true
}

case "${1:-}" in
	build) build ;;
	up) up ;;
	down) down ;;
	reset) down; up ;;
	logs) "$PODMAN" logs -f "$CONTAINER" ;;
	shell) "$PODMAN" exec -it -w /var/www/html "$CONTAINER" bash ;;
	*) sed -n '2,12p' "$0"; exit 1 ;;
esac
