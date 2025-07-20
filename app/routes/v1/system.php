<?php

$api->get('/v1/system/info', function () {
    phpinfo();
    exit();
});
