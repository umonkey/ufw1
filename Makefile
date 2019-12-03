all: help

help:
	@echo "make release   -- push to repositories"
	@echo "make test      -- test syntax with phpcs"

release:
	hg push default
	hg push github

test: test-syntax

test-syntax:
	vendor/bin/phpcs src
