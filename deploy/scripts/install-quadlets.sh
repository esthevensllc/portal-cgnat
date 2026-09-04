#!/usr/bin/env bash
set -Eeuo pipefail

root=/index2/portal-cgnat
source_dir="$root/current/deploy/quadlet"
target_dir=/etc/containers/systemd

[[ -d "$source_dir" ]] || { echo "No existe $source_dir" >&2; exit 1; }
[[ -f "$root/config/portal.env" ]] || { echo "Falta $root/config/portal.env" >&2; exit 1; }

install -d -m 0755 "$target_dir"

for source in "$source_dir"/*; do
    ln -sfn "$source" "$target_dir/$(basename "$source")"
done

systemctl daemon-reload
systemctl start \
    portal-cgnat-network.service \
    portal-redis.service \
    portal-app.service \
    portal-worker.service \
    portal-scheduler.service \
    portal-nginx.service

systemctl --no-pager --full status \
    portal-redis.service portal-app.service portal-nginx.service
