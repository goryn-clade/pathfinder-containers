# Pathfinder Container

**Pathfinder Container** is a docker-compose setup that contains a hassle free out of the box setup for [Pathfinder](https://developers.eveonline.com/https://github.com/exodus4d/pathfinder).

**Features**
* Setup Script for easy setup
* Password Protection of the setup page
* Socket Server running out of the box
* Automatic Restart in-case of crash
* Easy update with git tags
### How to run it

**Prerequisites**:
* [docker](https://docs.docker.com/)
* [docker-compose](https://docs.docker.com/)

1. **Create an [API-Key](https://developers.eveonline.com/) with the scopes listed in the [wiki](https://github.com/exodus4d/pathfinder/wiki/SSO-ESI)** 

2. **Clone the repo**
```shell
git clone --recurse-submodules  https://gitlab.com/techfreak/pathfinder-container
```

## Setup Script
3. **Run the setup script**
```shell                                                                                        
chmod +x setup.sh
./setup.sh
```

4. **Profit ! Connect it to nginx or let traefik discover it**
## Running it manually
3. **Edit the .env file and make sure every config option has an entry.**
```shell                                                                                        
#the folder path of this folder e.g /home/tech/Development/DOCKER/pathfinder-container
path=""
CONTAINER_NAME="pf"
DOMAIN=""
SERVER_NAME=""
APP_PASSWORD=""
MYSQL_PASSWORD=""
CCP_SSO_CLIENT_ID=""
CCP_SSO_SECRET_KEY=""
CCP_ESI_SCOPES="esi-location.read_online.v1,esi-location.read_location.v1,esi-location.read_ship_type.v1,esi-ui.write_waypoint.v1,esi-ui.open_window.v1,esi-universe.read_structures.v1,esi-corporations.read_corporation_membership.v1,esi-clones.read_clones.v1"
```

4. **Build & Run it** 
```shell
docker-compose build && docker-compose up -d
```

5. **Open the http://< your-domain >/setup page. Your username is pf and password is the password you set in APP_PASSWORD. Click on create database for eve_universe and pathfinder. And click on setup tables && fix column/keys.**


6. **Go back to your console and insert the eve universe dump with this command **
```shell                                                                                 
docker-compose exec pfdb /bin/sh -c "unzip -p eve_universe.sql.zip | mysql -u root -p\$MYSQL_ROOT_PASSWORD eve_universe";
``` 

7. **Profit ! Connect it to nginx or let traefik discover it**

### Acknowledgments
*  [exodus4d](https://github.com/exodus4d/) for pathfinder
*  [Markus Geiger](https://gist.github.com/blurayne/f63c5a8521c0eeab8e9afd8baa45c65e) for his awesome bash menu

### Authors
* techfreak

### License
This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details

