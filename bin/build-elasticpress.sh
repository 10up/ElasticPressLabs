#!/bin/bash

if [ $COMPOSER_DEV_MODE == 1 ]; then
	cd vendor/elasticpress
	composer install
	npm pkg set scripts.prepare=" "
	npm ci
	npm run build
fi