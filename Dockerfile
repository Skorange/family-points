FROM php:8.2-fpm-alpine

# Install Nginx, MySQL client, and PHP extensions
RUN apk add --no-cache nginx supervisor curl bash mariadb-client \
    && docker-php-ext-install pdo pdo_mysql

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Configure PHP
RUN mkdir -p /var/www/html && chown -R www-data:www-data /var/www

# Copy application
COPY web /var/www/html/

# Copy API
COPY api /var/www/html/api/

# Start services
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
