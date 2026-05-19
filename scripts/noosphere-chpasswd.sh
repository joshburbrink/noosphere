#!/bin/bash
# noosphere-chpasswd.sh <username>
# Reads password from stdin  -  called by the admin panel PHP.
# Never passes credentials through the process argument list.
if [ -z "$1" ]; then exit 1; fi
read -r PW
if [ -z "$PW" ]; then exit 1; fi
echo "$1:$PW" | /usr/sbin/chpasswd
