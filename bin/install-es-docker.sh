#!/usr/bin/env bash

if [ $# -lt 1 ]; then
	echo "usage: $0 start or $0 stop"
	exit 1
fi
ACTION=$1
if [ $ACTION != "start" ] && [ $ACTION != "stop" ]; then
	echo "usage: $0 start or $0 stop"
	exit 1
fi

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
EP_ES_DOCKER_DIR=${EP_ES_DOCKER_DIR-$TMPDIR/ep-es-docker}

# Check if git is installed
check_git_installed() {
    if ! command -v git > /dev/null; then
        echo "Error: git is not installed. Please install git and try again."
        exit 1
    fi
}

maybe_install() {
	if [ -d $EP_ES_DOCKER_DIR ]; then
		echo "Elasticsearch Docker already installed."
		return
	fi

	check_git_installed
	git clone --depth 1 --branch develop https://github.com/10up/ElasticPress/ $TMPDIR/elasticpress-develop

	rm -r $EP_ES_DOCKER_DIR
	mv $TMPDIR/elasticpress-develop/bin/es-docker $EP_ES_DOCKER_DIR
	rm -r $TMPDIR/elasticpress-develop
}

maybe_install

if [ $ACTION == "start" ]; then
	cd $EP_ES_DOCKER_DIR
	docker compose build --build-arg ES_VERSION=${ES_VERSION-8.16.1} && docker compose up -d
elif [ $ACTION == "stop" ]; then
	cd $EP_ES_DOCKER_DIR
	docker compose down
fi
