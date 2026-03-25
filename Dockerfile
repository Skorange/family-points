FROM php:8.2-fpm-alpine

# Install Nginx and MySQL client
RUN apk add --no-cache nginx supervisor curl bash mariadb-client

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Configure PHP
RUN mkdir -p /var/www/html && chown -R www-data:www-data /var/www

# Copy application
COPY web /var/www/html/

# Start services
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
