#!/usr/bin/env bash

# preserve original production files
mv ./docker-compose.yml ./docker-compose.production.yml
mv ./Dockerfile ./Dockerfile.production
mv ./static/pathfinder/environment.ini ./static/pathfinder/environment.production.ini
mv ./static/php/php.ini ./static/php/php.production.ini

# copy development versions 
cp ./development/docker-compose.development.yml ./docker-compose.yml
cp ./development/Dockerfile.development ./Dockerfile
cp ./development/environment.development.ini ./static/pathfinder/environment.ini
cp ./development/php.development.ini ./static/php/php.ini
cp ./development/xdebug.ini ./static/php/xdebug.ini

# set up launch file for vscode
mkdir -p .vscode && cp ./development/launch.json ./.vscode/launch.json

# seed .env file with dev presets
echo "path=\"$(pwd)\"" > ./.env
cat ./development/.env.development >> ./.env
