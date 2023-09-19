#!/bin/bash

npm ci
npm run build

rm ./elasticpress-labs.zip

git archive --output=elasticpress-labs.zip HEAD
zip -ur elasticpress-labs.zip dist vendor
