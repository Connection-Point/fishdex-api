<?php

namespace App\Domain\Shared\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case CatalogueEditor = 'catalogue_editor';
    case Reviewer = 'reviewer';
    case Support = 'support';
}
