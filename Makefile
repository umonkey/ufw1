all: help

help:
	@echo "make migrate   -- update database structure"
	@echo "make release   -- push to repositories"
	@echo "make sql       -- open database console"
	@echo "make test      -- test syntax with phpcs"

migrate:
	phinx migrate

release:
	-hg push default
	-hg push github

sql:
	sqlite3 -header data/database.sqlite3

test: test-syntax

test-syntax:
	vendor/bin/phpcs src
