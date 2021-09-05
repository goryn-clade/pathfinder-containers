#!/usr/bin/env bash

# preserve original production files
mv ./docker-compose.yml ./docker-compose.production.yml
mv ./Dockerfile ./Dockerfile.production
mv ./static/pathfinder/environment.ini ./static/pathfinder/environment.production.ini

# copy development versions 
cp ./deployment/docker-compose.development.yml ./docker-compose.yml
cp ./deployment/Dockerfile.development ./Dockerfile
cp ./deployment/environment.development.ini ./static/pathfinder/environment.ini

# set up launch file for vscode
mkdir -p .vscode && cp ./deployment/launch.json ./.vscode/launch.json

# seed .env file with dev presets
echo "path=\"$(pwd)\"" > ./.env
cat ./deployment/.env.development >> ./.env