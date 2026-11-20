<?php
declare(strict_types=1);

namespace DRESeo\Form;

use Laminas\Form\Form;

/**
 * The static-page SEO table's form. It declares no elements: the per-page
 * fields are rendered by hand in the view (one row per site page) and read
 * straight from the POST, and the CSRF token is the one Omeka's form
 * initializer adds to every form built by the element manager
 * (Omeka\Form\Initializer\Csrf, valid for 12 hours), so an editor working
 * through the table for more than an hour does not lose the edits.
 */
class PageSeoForm extends Form
{
}
