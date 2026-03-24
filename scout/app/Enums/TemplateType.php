<?php

namespace App\Enums;

enum TemplateType: string
{
    case SLR = 'slr';
    case Scoping = 'scoping';
    case Rapid = 'rapid';
    case RelatedWorks = 'related_works';
}
