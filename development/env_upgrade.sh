#!/usr/bin/env bash

# backup old .env
cp ./.env ./.env.old

# delete unnecessary lines
OS=$(uname)
if [ "$OS" == "Linux" ]; then
  sed -i '/PROJECT_ROOT.*$/d' .env
  sed -i '/CONTAINER_NAME.*$/d' .env
elif [ "$OS" == "Darwin" ]; then
  sed -i '' '/PROJECT_ROOT.*$/d' .env
  sed -i '' '/CONTAINER_NAME.*$/d' .env
fi


# insert new lines
echo "" >> .env
echo "LE_EMAIL=\"\"" >> .env
echo "MYSQL_HOST=\"mariadb\"" >> .env
echo "MYSQL_PORT=\"3306\"" >> .env
echo "MYSQL_USER=\"root\"" >> .env
echo "MYSQL_PF_DB_NAME=\"pathfinder\"" >> .env
echo "MYSQL_UNIVERSE_DB_NAME=\"eve_universe\"" >> .env
echo "MYSQL_CCP_DB_NAME=\"eve_lifeblood_min\"" >> .env
echo "REDIS_HOST=\"redis\"" >> .env
echo "REDIS_PORT=\"6379\"" >> .env
echo "PATHFINDER_SOCKET_HOST=\"pathfinder-socket\"" >> .env
echo "PATHFINDER_SOCKET_PORT=\"5555\"" >> .env
echo "SMTP_HOST=\"\"" >> .env
echo "SMTP_PORT=\"\"" >> .env
echo "SMTP_SCHEME=\"\"" >> .env
echo "SMTP_USER=\"\"" >> .env
echo "SMTP_PASS=\"\"" >> .env
echo "SMTP_FROM=\"\"" >> .env
echo "SMTP_ERROR=\"\"" >> .env

# sort new file alphabetically into .new file
cat .env | sort > ./.env.new

# print new file for inspection
echo ""
cat ./.env.new

# user input to check and save/abort
while true; do
  echo ""
  read -p "Overwrite .env with the new file? Y/n: " yn
  case $yn in
    [Yy]* ) mv ./.env.new ./.env; echo "Created new .env, old version backed up to .env.old"; break;;
    [Nn]* ) mv ./.env.old ./.env; echo "Aborting overwrite, new file saved as .env.new" ; exit;;
    * ) echo "Y/n";;
  esac
done
