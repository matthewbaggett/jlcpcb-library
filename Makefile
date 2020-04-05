all: build

install:
	@composer install

build: install
	@rm -f *.lbr validation.log assets/*.l#*
	@php build.php
	@#docker run --rm -it -v $PWD:/app gone/php:cli-7.4 php /app/build.php

missing-packages:
	@echo "Most popular missing packages:"
	@cat validation.log \
		| grep "doesn't exist\!" \
		| cut -f2 -d":" \
		| sed 's| Package ||g' \
		| sed "s|doesn't exist\!||g" \
		| sort \
		| uniq -c \
		| sort -r \
		| head -n 20
