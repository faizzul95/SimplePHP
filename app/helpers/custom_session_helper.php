<?php

function allSession($die = false)
{
    echo '<pre>';
    print_r($_SESSION);
    echo '</pre>';

    if ($die)
        die;
}

function startSession($param = NULL)
{
    foreach ($param as $sessionName => $sessionValue) {
        $_SESSION[$sessionName] = is_string($sessionValue) ? trim($sessionValue) : $sessionValue;
    }

    return $_SESSION;
}

function endSession($param = NULL)
{
    if (is_array($param)) {
        foreach ($param as $sessionName) {
            unset($_SESSION[$sessionName]);
        }
    } else {
        unset($_SESSION[$param]);
    }
}

function getSession($param = NULL)
{
    if (is_array($param)) {
        $sessiondata = [];
        foreach ($param as $sessionName) {
            array_push($sessiondata, $_SESSION[$sessionName]);
        }
        return $sessiondata;
    } else {
        return $_SESSION[$param] ?? null;
    }
}
