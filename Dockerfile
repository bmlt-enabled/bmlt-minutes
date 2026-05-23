FROM wordpress:7.0-php8.3-apache

RUN apt-get update && \
	apt-get install -y --no-install-recommends ssl-cert && \
	rm -r /var/lib/apt/lists/* && \
	a2enmod ssl rewrite expires && \
	a2ensite default-ssl

EXPOSE 80
EXPOSE 443
