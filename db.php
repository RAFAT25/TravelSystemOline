<?php

$conn_string = "host=ep-weathered-sea-ahagsdqv-pooler.c-3.us-east-1.aws.neon.tech
                port=5432
                dbname=neondb
                user=neondb_owner
                password=npg_6Lh8BTSKHfxg
                sslmode=require
                options='endpoint=ep-weathered-sea-ahagsdqv-pooler'";

$conn = pg_connect($conn_string);

if ($conn) {
    echo 'CONNECTED SUCCESSFULLY ❤️🔥';
} else {
    echo 'Connection failed 😢';
}
