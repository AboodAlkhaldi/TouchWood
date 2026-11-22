#!/usr/bin/env bash
# Run on the live server after every upload, from ~/touchwood:   bash deploy.sh
# The first time it also links the uploaded-images folder. It is safe to run again and again.
set -euo pipefail
cd "$(dirname "$0")"
[ -f .env ] || { echo "No .env here: upload it first (see the go-live steps)."; exit 1; }
php artisan migrate --force          # only does something when an update brings database changes
[ -L public/storage ] || php artisan storage:link
php artisan optimize                 # caches settings, routes and pages; must run after every upload
echo "Deployed: $(date)"
