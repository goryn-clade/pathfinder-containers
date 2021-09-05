#!/usr/bin/env bash

mv ./docker-compose.yml ./docker-compose.production.yml
mv ./Dockerfile ./Dockerfile.production
cp ./deployment/docker-compose.development.yml ./docker-compose.yml
cp ./deployment/Dockerfile.development ./Dockerfile
mkdir -p .vscode && cp ./deployment/launch.json ./.vscode/launch.json
echo "path=\"$(pwd)\"" > ./.env
cat ./deployment/.env.development >> ./.env