<?php

function fenom_function_profile_url($params)
{
    return cmsUser::getProfileURL($params['login']);
}
