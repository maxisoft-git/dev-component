<?php

function fenom_function_comments($params)
{

    if (!$params['target']) {
        return false;
    }
    if (!$params['target_id']) {
        return false;
    }

    cmsCore::includeComments();

    comments($params['target'], $params['target_id'], (is_array($params['labels']) ? $params['labels'] : array()), (isset($params['can_delete']) ? $params['can_delete'] : false));

    return true;

}
