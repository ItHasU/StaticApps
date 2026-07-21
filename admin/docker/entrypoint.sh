#!/bin/sh
set -e

mkdir -p /data/apps /data/history /data/.admin-state

if [ ! -d /data/apps/exemple ]; then
  cp -r /seed/apps/exemple /data/apps/exemple
fi

chown -R www-data:www-data /data

MAX_MB="${MAX_UPLOAD_SIZE_MB:-50}"
POST_MB=$((MAX_MB + 5))
cat > /usr/local/etc/php/conf.d/zz-uploads.ini <<EOF
upload_max_filesize = ${MAX_MB}M
post_max_size = ${POST_MB}M
EOF

if [ ! -f /data/index.html ]; then
  php -r 'require "/var/www/html/includes/apps.php"; regenerate_portal_menu();'
  chown -R www-data:www-data /data
fi

exec "$@"
