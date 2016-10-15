<?php

function fenom_function_callEvent($params)
{
    cmsCore::callEvent($params['event'], (empty($params['item']) ? array() : $params['item']));
}
