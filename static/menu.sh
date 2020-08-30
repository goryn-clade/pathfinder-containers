#!/bin/bash

##
# Pure BASH interactive CLI/TUI menu (single and multi-select/checkboxes)
#
# Author: Markus Geiger <mg@evolution515.net>
# Last revised 2011-09-11
#
# ATTENTION! TO BE REFACTORED! FIRST DRAFT!
#
# Demo
# 
# - ASCIINEMA
#   https://asciinema.org/a/Y4hLxnN20JtAlrn3hsC6dCRn8
#
# Inspired by
#
# - https://serverfault.com/questions/144939/multi-select-menu-in-bash-script
# - Copyright (C) 2017 Ingo Hollmann - All Rights Reserved
#   https://www.bughunter2k.de/blog/cursor-controlled-selectmenu-in-bash
#
# Notes
#
# - This is a hacky first implementation for my shell tools/dotfiles (ZSH)
# - Intention is to use it for CLI wizards (my aim is NOT a full blown curses TUI window interface)
# - I concerted TPUT to ANSII-sequences to spare command executions (e.g. `tput ed | xxd`)
#   reference: http://linuxcommand.org/lc3_adv_tput.php
# 
# Permission to copy and modify is granted under the Creative Commons Attribution 4.0 license
#

# Strict bash scripting (not yet)
# set -euo pipefail -o errtrace


# Templates for ui_widget_select
declare -xr UI_WIDGET_SELECT_TPL_SELECTED='\e[33m → %s \e[39m'
declare -xr UI_WIDGET_SELECT_TPL_DEFAULT="   \e[37m%s %s\e[39m"
declare -xr UI_WIDGET_MULTISELECT_TPL_SELECTED="\e[33m → %s %s\e[39m"
declare -xr UI_WIDGET_MULTISELECT_TPL_DEFAULT="   \e[37m%s %s\e[39m"
declare -xr UI_WIDGET_TPL_CHECKED="▣"
declare -xr UI_WIDGET_TPL_UNCHECKED="□"

# We use env variable to pass results since no interactive output from subshells and we don't wanna go hacky!
declare -xg UI_WIDGET_RC=-1


##
# Get type of a BASH variable (BASH ≥v4.0)
# 
# Notes
# - if references are encountered it will automatically try 
#   to resolve them unless '-f' is passed!
# - resolving functions can be seen as bonus since they also 
#   use `declare` (but with -fF). this behavior should  be removed!
# - bad indicates bad referencing which normally shouldn't occur!
# - types are shorthand and associative arrays map to "map" for convenience
#
# argument
#  -f               (optional) force resolvement of first hit 
#  <variable-name>  Variable name
#
# stdout
#  (nil|number|array|map|reference)
#
# stderr
#  -
#
# return
#  0 - always
typeof() {
	# __ref: avoid local to overwrite global var declaration and therefore emmit wrong results!
	local type="" resolve_ref=true __ref="" signature=() 
	if [[ "$1" == "-f" ]]; then
		# do not resolve reference
		resolve_ref=false; shift;
	fi
	__ref="$1"
	while [[ -z "${type}" ]] || ( ${resolve_ref} && [[ "${type}" == *n* ]] ); do		
		IFS=$'\x20\x0a\x3d\x22' && signature=($(declare -p "$__ref" 2>/dev/null || echo "na"))
		if [[ ! "${signature}" == "na" ]]; then 
			type="${signature[1]}" # could be -xn!
		fi	
		if [[ -z "${__ref}" ]] || [[ "${type}" == "na" ]] || [[ "${type}" == "" ]]; then
			printf "nil"
			return 0
		elif [[ "${type}" == *n* ]]; then 
			__ref="${signature[4]}"
		fi
	done
	case "$type" in
		*i*) printf "number";;
		*a*) printf "array";;
		*A*) printf "map";;
		*n*) printf "reference";;
		*) printf "string";;
    esac
}


##
# Removes a value from an array
#
# alternatives
#  array=( "${array[@]/$delete}"
#
# arguments
#  arg1  value
#  arg*  list or stdin
#
# stdout 
#  list with space seperator
array_without_value() {
	local args=() value="${1}" s
    shift
	for s in "${@}"; do
        if [ "${value}" != "${s}" ]; then
            args+=("${s}")
        fi
	done
	echo "${args[@]}"
}


##
# check if a value is in an array
#
# alternatives
#  array=( "${array[@]/$delete}"
#
# arguments
#  arg1  value
#  arg*  list or stdin
#
# stdout 
#  list with space seperator
array_contains_value() {
  local e match="$1"
  shift
  for e; do [[ "$e" == "$match" ]] && return 0; done
  return 1
}

##
# BASH only string to hex
# 
# stdout
#   hex squence
str2hex_echo() {
    # USAGE: hex_repr=$(str2hex_echo "ABC")
    #        returns "0x410x420x43"
    local str=${1:-$(cat -)}
    local fmt=""
    local chr
    local -i i
    printf "0x"
    for i in `seq 0 $((${#str}-1))`; do
        chr=${str:i:1}
        printf  "%x" "'${chr}"
    done
}

##
# Read key and map to human readable output
#
# notes
#  output prefix (concated by `-`)
#    c    ctrl key
#    a    alt key
#    c-a  ctrl+alt key
#  use F if you mean shift!
#  uppercase `f` for `c+a` combination is not possible!
#
# arguments
#  -d           for debugging keycodes (hex output via xxd)
#  -l           lowercase all chars
#  -l <timeout> timeout
#
# stdout
#   mapped key code like in notes
ui_key_input() {
    local key
    local ord
    local debug=0
    local lowercase=0
    local prefix=''
    local args=()
    local opt

    while (( "$#" )); do
        opt="${1}"
        shift
        case "${opt}" in
            "-d") debug=1;;
            "-l") lowercase=1;;
            "-t") args+=(-t $1); shift;;
        esac
    done
    IFS= read ${args[@]} -rsn1 key 2>/dev/null >&2
    read -sN1 -t 0.0001 k1; read -sN1 -t 0.0001 k2; read -sN1 -t 0.0001 k3
    key+="${k1}${k2}${k3}"
    if [[ "${debug}" -eq 1 ]]; then echo -n "${key}" | str2hex_echo; echo -n " : " ;fi;
    case "${key}" in
        '') key=enter;;
        ' ') key=space;;
        $'\x1b') key=esc;;
        $'\x1b\x5b\x36\x7e') key=pgdown;;
        $'\x1b\x5b\x33\x7e') key=erase;;
        $'\x7f') key=backspace;;
        $'\e[A'|$'\e0A  '|$'\e[D'|$'\e0D') key=up;;
        $'\e[B'|$'\e0B'|$'\e[C'|$'\e0C') key=down;;
        $'\e[1~'|$'\e0H'|$'\e[H') key=home;;
        $'\e[4~'|$'\e0F'|$'\e[F') key=end;;
        $'\e') key=enter;;
        $'\e'?) prefix="a-"; key="${key:1:1}";; 
    esac

    # only lowercase if we have a single letter
    # ctrl key is hidden within char code (no o)
    if [[ "${#key}" == 1 ]]; then
        ord=$(LC_CTYPE=C printf '%d' "'${key}")
        if [[ "${ord}" -lt 32 ]]; then
            prefix="c-${prefix}"
            # ord=$(([##16] ord + 0x60))
            # let "ord = [##16] ${ord} + 0x60"
            ord="$(printf "%X" $((ord + 0x60)))"
            key="$(printf "\x${ord}")"
        fi       
        if [[ "${lowercase}" -eq 1 ]]; then
            key="${key,,}"
        fi
    fi

    echo "${prefix}${key}"
}

##
# UI Widget Select
#
# arguments
#  -i <[menu-item(s)] …>      menu items
#  -m                         activate multi-select mode (checkboxes)
#  -k <[key(s)] …>            keys for menu items (if none given indexes are used)
#  -s <[selected-keys(s)] …>  selected keys (index or key)
#                             if keys are used selection needs to be keys
#  -c                         clear complete menu on exit
#  -l                         clear menu and leave selections
#   
# env
#  UI_WIDGET_RC will be selected index or -1 of nothing was selected
#
# stdout
#  menu display - don't use subshell since we need interactive shell and use tput!
#
# stderr
#  sometimes (trying to clean up)
# 
# return
#   0  success
#  -1  cancelled 
ui_widget_select() {
    local menu=() keys=() selection=() selection_index=() 
    local cur=0 oldcur=0 collect="item" select="one" 
    local sel="" marg="" drawn=false ref v=""
    local opt_clearonexit=false opt_leaveonexit=false
    export UI_WIDGET_RC=-1
    while (( "$#" )); do
        opt="${1}"; shift
        case "${opt}" in
            -k) collect="key";;
            -i) collect="item";;
            -s) collect="selection";;
            -m) select="multi";;
            -l) opt_clearonexit=true; opt_leaveonexit=true;;
            -c) opt_clearonexit=true;;
            *)
            if [[ "${collect}" == "selection" ]]; then
                selection+=("${opt}")
            elif [[ "${collect}" == "key" ]]; then
                keys+=("${opt}")
            else
                menu+=("$opt")
            fi;;
        esac
    done

    # sanity check
    if [[ "${#menu[@]}" -eq 0 ]]; then
        >&2 echo "no menu items given"
        return 1
    fi

    if [[ "${#keys[@]}" -gt 0 ]]; then
        # if keys are used 
        # sanity check
        if [[ "${#keys[@]}" -gt 0 ]] && [[ "${#keys[@]}" != "${#menu[@]}" ]]; then
            >&2 echo "number of keys do not match menu options!"
            return 1
        fi
        # map keys to indexes
        selection_index=()
        for sel in "${selection[@]}"; do
            for ((i=0;i<${#keys[@]};i++)); do
                if [[ "${keys[i]}" == "${sel}" ]]; then
                    selection_index+=("$i")
                fi
            done
        done
    else
        # if no keys are used assign by indexes
        selection_index=(${selection[@]})
    fi

    clear_menu() {
        local str=""
        for i in "${menu[@]}"; do str+="\e[2K\r\e[1A"; done
        echo -en "${str}"
    }

    ##
    # draws menu in three different states
    # - initial: draw every line as intenden
    # - update: only draw updated lines and skip existing
    # - exit: only draw selected lines
    draw_menu() {
        local mode="${initial:-$1}" check=false check_tpl="" str="" msg="" tpl_selected="" tpl_default="" marg=() 

        if ${drawn} && [[ "$mode" != "exit" ]]; then 
            # reset position
            str+="\r\e[2K"
            for i in "${menu[@]}"; do str+="\e[1A"; done
            # str+="${TPUT_ED}"
        fi
        if [[ "$select" == "one" ]]; then
            tpl_selected="$UI_WIDGET_SELECT_TPL_SELECTED"
            tpl_default="$UI_WIDGET_SELECT_TPL_DEFAULT"
        else
            tpl_selected="$UI_WIDGET_MULTISELECT_TPL_SELECTED" 
            tpl_default="$UI_WIDGET_MULTISELECT_TPL_DEFAULT"
        fi

        for ((i=0;i<${#menu[@]};i++)); do
            check=false
            if [[ "$select" == "one" ]]; then
                # single selection
                marg=("${menu[${i}]}")
                if [[ ${cur} == ${i} ]]; then
                    check=true
                fi
            else
                # multi-select
                check_tpl="$UI_WIDGET_TPL_UNCHECKED"; 
                if array_contains_value "$i" "${selection_index[@]}"; then
                    check_tpl="$UI_WIDGET_TPL_CHECKED"; check=true
                fi
                marg=("${check_tpl}" "${menu[${i}]}")
            fi
            if [[ "${mode}" != "exit" ]] && [[ ${cur} == ${i} ]]; then
                str+="$(printf "\e[2K${tpl_selected}" "${marg[@]}")\n";
            elif ([[ "${mode}" != "exit" ]] && ([[ "${oldcur}" == "${i}" ]] || [[ "${mode}" == "initial" ]])) || (${check} && [[ "${mode}" == "exit" ]]); then
                str+="$(printf "\e[2K${tpl_default}" "${marg[@]}")\n";
            elif [[ "${mode}" -eq "update" ]] && [[ "${mode}" != "exit" ]]; then
                str+="\e[1B\r"
            fi
        done
        echo -en "${str}"
        export drawn=true
    }

    # initial draw
    draw_menu initial 
    
    # action loop
    while true; do
        oldcur=${cur}
        key=$(ui_key_input)
        case "${key}" in
            up|left|i|j) ((cur > 0)) && ((cur--));;
            down|right|k|l) ((cur < ${#menu[@]}-1)) && ((cur++));;
            home)  cur=0;;
            pgup) let cur-=5; if [[ "${cur}" -lt 0 ]]; then cur=0; fi;;
            pgdown) let cur+=5; if [[ "${cur}" -gt $((${#menu[@]}-1)) ]]; then cur=$((${#menu[@]}-1)); fi;;
            end) ((cur=${#menu[@]}-1));;
            space) 
                if [[ "$select" == "one" ]]; then
                    continue
                fi
                if ! array_contains_value "$cur" "${selection_index[@]}"; then
                    selection_index+=("$cur")
                else
                    selection_index=($(array_without_value "$cur" "${selection_index[@]}"))
                fi
                ;;
            enter) 
                if [[ "${select}" == "multi" ]]; then
                    export UI_WIDGET_RC=()
                    for i in ${selection_index[@]}; do
                        if [[ "${#keys[@]}" -gt 0 ]]; then
                            export UI_WIDGET_RC+=("${keys[${i}]}")
                        else
                            export UI_WIDGET_RC+=("${i}")
                        fi
                    done
                else
                    if [[ "${#keys[@]}" -gt 0 ]]; then
                        export UI_WIDGET_RC="${keys[${cur}]}";
                    else
                        export UI_WIDGET_RC=${cur};
                    fi 
                fi
                if $opt_clearonexit; then clear_menu; fi
                if $opt_leaveonexit; then draw_menu exit; fi
                return
                ;;
            [1-9])
                let "cur = ${key}"
                if [[ ${#menu[@]} -gt 9 ]]; then
                    echo -n "${key}"
                    sleep 1
                    key="$(ui_key_input -t 0.5 )"
                    if [[ "$key" =~ [0-9] ]]; then
                        let "cur = cur * 10 + ${key}"
                    elif [[ "$key" != "enter" ]]; then
                        echo -en "\e[2K\r$key invalid input!"
                        sleep 1
                    fi
                fi 
                let "cur = cur - 1"
                if [[ ${cur} -gt ${#menu[@]}-1 ]]; then
                    echo -en "\e[2K\rinvalid index!"
                    sleep 1
                    cur="${oldcur}"
                fi
                echo -en "\e[2K\r"
                ;;
            esc|q|$'\e')   
                if $opt_clearonexit; then clear_menu; fi
                return 1;;
        esac

        # Redraw menu
        draw_menu update
    done
}







