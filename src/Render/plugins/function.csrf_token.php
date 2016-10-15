<?php

function fenom_function_csrf_token($params)
{
    return User::getCsrfToken();
}
