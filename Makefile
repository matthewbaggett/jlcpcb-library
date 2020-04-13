all: reset build missing-packages

reset:
	reset

install:
	@composer install --optimize-autoloader

clean:
	@rm -f validation.log assets/*.l#* lbr/*.l#*

build: install clean
	@php build.php
	@#docker run --rm -it -v $PWD:/app gone/php:cli-7.4 php /app/build.php
	@cat validation.log

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
