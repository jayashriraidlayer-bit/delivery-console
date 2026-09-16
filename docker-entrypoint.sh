#!/bin/bash
set -e

# Most PaaS hosts (Railway, etc.) inject the port to listen on via $PORT.
# Rewrite Apache's config to that port every time the container starts,
# since $PORT can differ between deployments.
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
