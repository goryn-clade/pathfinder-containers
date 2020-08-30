#!/bin/bash
. static/menu.sh
source $CWD\.env


#https://bytefreaks.net/gnulinux/bash/cecho-a-function-to-print-using-different-colors-in-bash
cecho () {
  declare -A colors;
  colors=(\
    ['black']='\E[0;47m'\
    ['red']='\E[0;31m'\
    ['green']='\E[0;32m'\
    ['yellow']='\E[0;33m'\
    ['blue']='\E[0;34m'\
    ['magenta']='\E[0;35m'\
    ['cyan']='\E[0;36m'\
    ['white']='\E[0;37m'\
    );



  local defaultMSG="";
  local defaultColor="black";
  local defaultNewLine=true;



  while [[ $# -gt 1 ]];
  do
    key="$1";



    case $key in
      -c|--color)
	color="$2";
	shift;
	;;

      -n|--noline)
	newLine=false;
	;;
      *)
	# unknown option
	;;
    esac
    shift;
  done

  message=${1:-$defaultMSG};   # Defaults to default message.
  color=${color:-$defaultColor};   # Defaults to default color, if not specified.
  newLine=${newLine:-$defaultNewLine};

  echo -en "${colors[$color]}";
  echo -en "$message";
  if [ "$newLine" = true ] ; then
    echo;
  fi
  tput sgr0; #  Reset text attributes to normal without clearing screen.
  return;
}


function wizzard {
  cecho -c 'blue' "$@";
  #echo -e "\e[4mMENU: select-one, using assoc keys, preselection, leave selected options\e[24m"
  #declare -A options2="${$2}"
  #declare -A options2=( [foo]="Hallo" [bar]="World" [baz]="Record")
  ui_widget_select -l -k "${!menu[@]}" -s bar -i "${menu[@]}"
  #echo "Return code: $?"
  #return "$?"
}

function editVariable(){
if [ "$1" == "" ]; then
  read -p "Please set a config value for $3 [$2]: " VALUE
  VALUE="${VALUE:-$2}"
 #sed -i "s/$3=.*/$3=\"$VALUE\"/g" .env
 sed -i "s@$3=.*@$3=\"$VALUE\"@g" .env
 #sed -i  's/'"$3"'=.*/'"$3"'='"${VALUE}"'/' .env
 #sed -i -e 's/'$3'=.*/'$3'="'"$VALUE"'"/g' .env
 #sed -i "s/$3=.*/$3=$VALUE/g" .env
fi
}
function setConfig(){
editVariable "$path" "$PWD" "path"
editVariable "$CONTAINER_NAME" "pf" "CONTAINER_NAME"
editVariable "$DOMAIN" "localhost" "DOMAIN"
editVariable "$SERVER_NAME" "CARLFINDER" "SERVER_NAME"
editVariable "$MYSQL_PASSWORD" "" "MYSQL_PASSWORD"
editVariable "$CCP_SSO_CLIENT_ID" "" "CCP_SSO_CLIENT_ID"
editVariable "$CCP_SSO_SECRET_KEY" "" "CCP_SSO_SECRET_KEY"
editVariable "$CCP_ESI_SCOPES" "esi-location.read_online.v1,esi-location.read_location.v1,esi-location.read_ship_type.v1,esi-ui.write_waypoint.v1,esi-ui.open_window.v1,esi-universe.read_structures.v1,esi-corporations.read_corporation_membership.v1,esi-clones.read_clones.v1" "CCP_ESI_SCOPES"
source $CWD\.env
}

while [[ $path == "" ]] || [[ $CONTAINER_NAME == "" ]] || [[ $DOMAIN == "" ]] || [[ $SERVER_NAME == "" ]] || [[ $MYSQL_PASSWORD == "" ]] || [[ $CCP_SSO_CLIENT_ID == "" ]] || [[ $CCP_SSO_SECRET_KEY == "" ]] || [[ $CCP_ESI_SCOPES == "" ]]; do
  setConfig
done

docker container inspect $CONTAINER_NAME > /dev/null 2>&1;
if [ $? -eq 1 ];
then
  declare -A menu=( [no]="NO" [yes]="YES")
  wizzard "You did not build the container ! Do you want the setup to do it ? "
  if [ ${menu[$UI_WIDGET_RC]} == "YES" ]; then
    docker-compose build
    tput clear;
  fi
fi

running=$(docker container inspect -f '{{.State.Status}}' $CONTAINER_NAME)
if [[ $running != "running" ]];then 
  declare -A menu=( [no]="NO" [yes]="YES")
  wizzard "Do you want to run the container ?"
  if [ ${menu[$UI_WIDGET_RC]} == "YES" ]; then
    docker-compose up -d
  fi
fi

declare -A menu=( [no]="NO" [yes]="YES ")
 cecho -c 'blue' "Do you want to import the eve_universe database ?"; 
 cecho -c 'red' "DISCLAIMER: Before you do that go to http://$DOMAIN/setup page (USERNAME:'pf' & password is your APP_PASSWORD) and hit create database, setup tables & fix column keys. After you did that select YES."
 wizzard "";
if [ ${menu[$UI_WIDGET_RC]} == "YES" ]; then
docker-compose exec pfdb /bin/sh -c "unzip -p eve_universe.sql.zip | mysql -u root -p\$MYSQL_ROOT_PASSWORD eve_universe";
fi

