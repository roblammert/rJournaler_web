FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libonig-dev \
        libzip-dev \
        pkg-config \
        python3-pip \
        python3-venv \
        python3 \
        unzip \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html
RUN python3 -m venv /var/www/html/.venv \
    && /var/www/html/.venv/bin/python -m pip install --no-cache-dir --upgrade pip \
    && /var/www/html/.venv/bin/python -m pip install --no-cache-dir noaa-sdk==0.1.21
EXPOSE 8080
