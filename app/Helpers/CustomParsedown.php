<?php

namespace App\Helpers;

use App\Helpers\ParsedownExtra;

class CustomParsedown extends ParsedownExtra
{
    protected function blockFencedCodeComplete($Block)
    {
        $Block = parent::blockFencedCodeComplete($Block);

        if (!isset($Block['element']['text']['attributes']['class'])) {
            $Block['element']['text']['attributes']['class'] = 'language-plain';
        } else {
            $Block['element']['text']['attributes']['class'] = 'language-' . $Block['element']['text']['attributes']['class'];
        }

        return $Block;
    }
}