#!/bin/bash
# $1 = mode name passed by FPP from the command argument
MODE="${1:-always_on}"
curl -s -X POST http://localhost:5001/api/config \
     -H 'Content-Type: application/json' \
     -d "{\"trigger_mode\": \"$MODE\"}"
