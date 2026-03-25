#!/bin/bash

# Start PHP-FPM in background
php-fpm &

# Start Nginx in foreground
nginx -g 'daemon off;'
