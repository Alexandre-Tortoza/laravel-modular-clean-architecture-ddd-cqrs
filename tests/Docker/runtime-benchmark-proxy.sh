#!/bin/sh
set -eu

directory=$1
sed -i 's/server php:9000 resolve;/server php:8000 resolve;/' "$directory/http.conf"
sed -i '/include \/etc\/nginx\/fastcgi_params;/,/fastcgi_pass php_fpm;/c\        proxy_set_header Host $http_host;\n        proxy_set_header X-Forwarded-Proto $scheme;\n        proxy_connect_timeout 5s;\n        proxy_send_timeout 30s;\n        proxy_read_timeout 30s;\n        proxy_pass http://php_fpm$request_uri;' "$directory/application.conf"
