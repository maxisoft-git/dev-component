<?php

function fenom_function_add_js($params)
{
    cmsPage::getInstance()->addHeadJS($params['file']);
}
