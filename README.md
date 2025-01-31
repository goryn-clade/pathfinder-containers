# Pathfinder Containers

[![Docker Image Master Branch](https://github.com/goryn-clade/pathfinder-containers/actions/workflows/docker-image.yml/badge.svg?branch=master)](https://github.com/goryn-clade/pathfinder-containers/actions/workflows/docker-image.yml)


A fork of techfreak's [Pathfinder-container](https://gitlab.com/techfreak/pathfinder-container/) docker-compose solution for Pathfinder that is designed to work with Goryn Clade's [Pathfinder](https://github.com/goryn-clade/pathfinder/) fork, using [Traefik](https://traefik.io/) as a reverse proxy to expose the docker container.

1. [Installation](#installation)
1. [Using Traefik](#using-traefik)
1. [Development](#development)

## Installation

**Prerequisites**:
* [docker](https://docs.docker.com/)
* [docker-compose](https://docs.docker.com/)

> **Note**: The Docker-compose file uses Compose v3.8, so requires Docker Engine 19.03.0+

</br>


1. **Create an API-Key**
    * Go the [Eve Online Developer portal](https://developers.eveonline.com/)
    * After signing in go to "MANAGE APPLICATIONS" → "CREATE NEW APPLICATION"
    * Choose a name for your application (e.g. "Pathfinder Production")
    * Enter a Description for this installation
    * Change "CONNECTION TYPE" to "Authentication & API Access"
    * Add the following "PERMISSIONS":
      * `esi-location.read_online.v1`
      * `esi-location.read_location.v1`
      * `esi-location.read_ship_type.v1`
      * `esi-ui.write_waypoint.v1`
      * `esi-ui.open_window.v1`
      * `esi-universe.read_structures.v1`
      * `esi-corporations.read_corporation_membership.v1`
      * `esi-clones.read_clones.v1`
      * `esi-characters.read_corporation_roles.v1`
      * `esi-search.search_structures.v1`
    * Set your "CALLBACK URL" to `https://[YOUR_DOMAIN]/sso/callbackAuthorization`</br></br>
  
  
1. **Clone the repo**
    ```shell
    git clone --recurse-submodules  https://github.com/goryn-clade/pathfinder-containers.git
    ```

1. **Create a *.env* file (copy .env.example) and make sure every config option has an entry.**
    ```shell
    PROJECT_ROOT=""       # The path of the cloned repo
    CONTAINER_NAME="pf"   # docker container name prefix
    DOMAIN=""             # The domain you will be using
    APP_PASSWORD=""       # Password for /setup
    MYSQL_HOST="mariadb"  # mysql host
    MYSQL_PORT="3306"     # default mysql port
    MYSQL_USER="root"     # mysql root user
    MYSQL_PASSWORD=""     # mysql Password
    CCP_SSO_CLIENT_ID=""  # Use the SSO tokens created in step 1
    CCP_SSO_SECRET_KEY=""
    CCP_ESI_SCOPES="esi-location.read_online.v1,esi-location.read_location.v1,esi-location.read_ship_type.v1,esi-ui.write_waypoint.v1,esi-ui.open_window.v1,esi-universe.read_structures.v1,esi-corporations.read_corporation_membership.v1,esi-clones.read_clones.v1,esi-characters.read_corporation_roles.v1"
    MYSQL_PF_DB_NAME="pathfinder" # mysql pathfinder table name
    MYSQL_UNIVERSE_DB_NAME="eve_universe"
    MYSQL_CCP_DB_NAME="eve_lifeblood_min"
    REDIS_HOST="redis"    # redis host
    REDIS_PORT="6379"     # default redis port
    PATHFINDER_SOCKET_HOST="pathfinder-socket" # domain of the websocket container, relative to the main pf container
    PATHFINDER_SOCKET_PORT="5555"              # default tcp socket port
    SMTP_HOST=""
    SMTP_PORT=""
    SMTP_SCHEME=""
    SMTP_USER=""
    SMTP_PASS=""
    SMTP_FROM=""
    SMTP_ERROR=""
    LE_EMAIL="your-email@example.com" #Add your email address for notifications if the SSL cert renewal fails.
> The `PROJECT_ROOT` key is the *absolute* path to the project directory, ie if you have clone it to /app/pathfinder-containers, this is the value you should enter. If you're unsure of the absolute path, you can use the command `pwd` to get the full absolute path of the current directory.

1. **Edit the *config/pathfinder/pathfinder.ini*** to your liking

    Recommended options to change:
    * `[PATHFINDER]`
        * `NAME`- the tab title when viewing your Pathfinder
    * `[PATHFINDER.LOGIN]`
        * `COOKIE_EXPIRE` - expire age (in days) for login cookies. [read more](https://github.com/exodus4d/pathfinder/issues/138#issuecomment-216036606)
        * `SESSION_SHARING` - Share maps between logged in characters in the same browser session. [read more](https://github.com/goryn-clade/pathfinder/releases/tag/v2.1.1)
        * `CHARACTER` - Character allow-list. Comma separated string of character ids. (empty = "no restriction")
        * `CORPORATION` - Corporation allow-list. Comma separated string of corporation ids. (empty = "no restriction")
        * `ALLIANCE` - Alliance allow-list. Comma separated string of alliance ids. (empty = "no restriction")
    * `[PATHFINDER.MAP.PRIVATE]`, `[PATHFINDER.MAP.PRIVATE]`, `[PATHFINDER.MAP.ALLIANCE]`
        * `LIFETIME` - expire time (in days) until a map type will be deleted (by cronjob)    
</br></br>

    
1. **Build & Run it**
    ```shell
    docker network create web && docker-compose up -d
    ```

1. **Open the http://< your-domain >/setup page.**
   * Your username is `pf` and password is the password you set in `APP_PASSWORD` in the *.env* file.
   * Find the database section of the setup page, for both "pf" and "eve_universe" databases click the **"create database"** button. 
   * Once the page has reloaded, click the **"setup tables"**, and then **"fix columns/keys"**.
</br></br>

1. **Go back to your console and insert the eve universe dump with this command:**
    ```shell
    docker-compose exec pfdb /bin/sh -c "unzip -p eve_universe.sql.zip | mysql -u root -p\$MYSQL_ROOT_PASSWORD eve_universe";

1. **When everything works, configure Traefik correctly for production**
    * Remove the staging CA server line  from `docker-compose.yml`from the `command` block of the traefik service definition. 
    * Delete the `./letsencrypt/acme.json` configuration file so Let's Encrypt will get a new certificate.</br></br>
    * If you are not the root user on your host you may need to edit file permissions. Docker-engine creates the `letsencrypt` director as root user, which means that you would need to prefix `sudo` on any future docker commands (`sudo docker-compose up` etc). To avoid doing this you can take ownership of the letsencrypt directory by running `sudo chown -R $USER ./letsencrypt`.


> Hint: If you need to make changes, perform your edits first, then do `docker-compose down` to bring down the project, and then `docker-compose up --build -d` to rebuild the containers and run them again.

</br>

---
</br>

### Using Traefik

To keep things simple, the structure of this project assumes that you will use Traefik to provide access to your Pathfinder docker container and nothing else. As such, Traefik containers start and stop with the Pathfinder containers. 

If you want to run other services in docker on the same host that also need to be exposed to the web, you should strongly consider splitting Traefik into a separate project with its own docker-compose file. This will allow you to take pathfinder project offline for maintenance without affecting other containers that rely on Traefik.

</br>

---

</br>

## Development

Some Development configurations that have worked well for me have been saved in the `development/` directory, including step debugging for VsCode using [xdebug](https://xdebug.org/)

Development configs and docker files can be quickly restored using: 


 ```shell
chmod +x ./development/development.sh && ./development/development.sh
```

This creates a partial `.env` file, but you will need to add your CCP SSO client and keys manually, if you want to copy development files without overwriting your .env file add the flag `--noenv` when running the script.

It's best to create a new SSO application for development work, so that you can set the callback url to `https://localhost/sso/callbackAuthorization`.

</br>

---

</br>

## Acknowledgments
*  [exodus4d](https://github.com/exodus4d/) for pathfinder
* [techfreak](https://gitlab.com/techfreak/pathfinder-container) for the original Pathfinder-container project
* [johnschultz](https://gitlab.com/johnschultz/pathfinder-container/) for improvements to the traefik config
* [tyrheimdaleve](https://github.com/TyrHeimdalEVE/pathfinder_esi) for maintaining the pathfinder_esi dependency

## Authors
* techfreak
* johnschultz
* samoneilll

## License
This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details

