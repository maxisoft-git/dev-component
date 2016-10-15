<?php

function fenom_function_printModules($params)
{
    cmsPage::getInstance()->printModules($params['pos']);
}
