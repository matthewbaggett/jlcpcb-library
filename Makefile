all: build

install:
	@composer install

build: install
	@rm -f *.lbr
	@php build.php
	@#docker run --rm -it -v $PWD:/app gone/php:cli-7.4 php /app/build.php