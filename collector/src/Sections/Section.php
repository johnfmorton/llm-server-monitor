<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Context;

interface Section
{
    /** Key in the output JSON. */
    public function name(): string;

    /**
     * @param array $sites from Sites::discover()
     * @return array JSON-ready summary for this section
     */
    public function collect(Context $ctx, array $sites): array;
}
