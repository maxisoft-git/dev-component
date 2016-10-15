<?php

function fenom_function_template_dir($params)
{
    $type = 'frontend';
    if (defined('VALID_CMS_ADMIN')) {
        $type = 'backend';
    }

    return '/themes/' . $type . '/' . TEMPLATE;
}
