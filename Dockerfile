FROM php:7.4.3-cli
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

WORKDIR /app

COPY . /app

RUN \
  apt-get update && apt-get install -y git unzip && \
  ln -s /usr/local/bin/php /usr/bin/php && \
  curl -sS https://getcomposer.org/installer | php -- --no-ansi --install-dir=/usr/bin --filename=composer && \
  composer install

EXPOSE 8010

ENTRYPOINT ["/app/run.php"]
