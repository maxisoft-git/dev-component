<?php

function fenom_function_printPathway($params)
{
    cmsPage::getInstance()->printPathway($params['sep']);
}
