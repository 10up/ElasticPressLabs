#!/bin/bash

ACF_PRO_LICENSE_KEY=""
DISPLAY_HELP=0
EP_HOST=""
EP_CREDENTIALS=""
EP_INDEX_PREFIX=""
WP_VERSION=""
WC_VERSION=""

for opt in "$@"; do
	case $opt in
    --acf-pro-license=*)
      ACF_PRO_LICENSE_KEY="${opt#*=}"
      ;;
    -H=*|--ep-host=*)
      EP_HOST="${opt#*=}"
      ;;
    -S=*|--es-shield=*)
      EP_CREDENTIALS="${opt#*=}"
      ;;
    -p=*|--ep-index-prefix=*)
      EP_INDEX_PREFIX="${opt#*=}"
      ;;
    -wp=*|--wp-version=*)
      WP_VERSION="${opt#*=}"
      ;;
    -wc=*|--wc-version=*)
      WC_VERSION="${opt#*=}"
      ;;
    -h|--help|*)
      DISPLAY_HELP=1
      ;;
	esac
done

PLUGIN_NAME=$(basename "$PWD")

if [ $DISPLAY_HELP -eq 1 ]; then
	echo "This script will setup the environment for the Cypress tests"
	echo "Usage: ${0##*/} [OPTIONS...]"
	echo
	echo "Optional parameters:"
	echo "--acf-pro-license=*       ACF Pro License Key."
	echo "-H=*, --ep-host=*         The remote Elasticsearch Host URL."
	echo "-S=*, --es-shield=*       The Elasticsearch credentials, used in the ES_SHIELD constant."
	echo "-p=*, --ep-index-prefix=* The Elasticsearch credentials, used in the EP_INDEX_PREFIX constant."
	echo "-W=*, --wp-version=*      WordPress Core version."
	echo "-w=*, --wc-version=*      WooCommerce version."
	echo "-h|--help                 Display this help screen"
	exit
fi

# Set twentytwentyone as the active theme here, as 2025 won't work with WP 6.2
./bin/wp-env-cli tests-wordpress "wp --allow-root theme activate twentytwentyone"

# Fix the debug-bar-elasticpress dependency of ElasticPress
./bin/wp-env-cli tests-wordpress "wp --allow-root plugin install debug-bar-elasticpress"
./bin/wp-env-cli tests-wordpress "sed -i \"s/Requires Plugins:  elasticpress/Requires Plugins:  $PLUGIN_NAME/\" /var/www/html/wp-content/plugins/debug-bar-elasticpress/debug-bar-elasticpress.php"
./bin/wp-env-cli tests-wordpress "wp --allow-root plugin activate debug-bar-elasticpress"

if [ ! -z $WP_VERSION ]; then
	./bin/wp-env-cli tests-wordpress "wp --allow-root core update --version=${WP_VERSION} --force"
	./bin/wp-env-cli tests-wordpress "wp --allow-root core update-db"
fi

./bin/wp-env-cli tests-wordpress "wp --allow-root plugin activate elasticpress-labs"

./bin/wp-env-cli tests-wordpress "wp --allow-root rewrite structure '/%postname%/'"

if [ -z $EP_HOST ]; then
	# Determine what kind of env we're in
	if [ "$(uname | tr '[:upper:]' '[:lower:]')" = "darwin" ]; then
		echo "Running tests on $(uname)"
		EP_HOST="http://host.docker.internal:8890/"
	elif grep -qi microsoft /proc/version; then
		echo "Running tests on Windows"
		EP_HOST="http://host.docker.internal:8890/"
	else
		echo "Running tests on $(uname)"
		# 172.17.0.1 is the IP Address of host when using Linux
		EP_HOST="http://172.17.0.1:8890/"
	fi
fi
./bin/wp-env-cli tests-wordpress "wp --allow-root config set EP_HOST ${EP_HOST}"

if [ ! -z $EP_CREDENTIALS ]; then
	./bin/wp-env-cli tests-wordpress "wp --allow-root config set EP_CREDENTIALS ${EP_CREDENTIALS}"
fi

if [ ! -z $EP_INDEX_PREFIX ]; then
	./bin/wp-env-cli tests-wordpress "wp --allow-root config set EP_INDEX_PREFIX ${EP_INDEX_PREFIX}"
fi

./bin/wp-env-cli tests-wordpress "wp --allow-root elasticpress sync --setup --yes --show-errors"

./bin/wp-env-cli tests-wordpress "wp --allow-root option set posts_per_page 5"
./bin/wp-env-cli tests-wordpress "wp --allow-root user meta update admin edit_post_per_page 5"
./bin/wp-env-cli tests-wordpress "wp --allow-root user update admin --user_pass=password"
