<?php

function fenom_modifier_nospam($email, $filterLevel = 'normal')
{
    $email = strrev($email);
    $email = preg_replace('[\.]', '/', $email, 1);
    $email = preg_replace('[@]', '/', $email, 1);

    if ($filterLevel == 'low') {
        $email = strrev($email);
    }

    return $email;
}
