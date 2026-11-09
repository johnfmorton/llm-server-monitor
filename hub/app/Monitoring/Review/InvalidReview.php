<?php

namespace App\Monitoring\Review;

use RuntimeException;

/**
 * The model answered, but not with a usable review (cut off, or not matching the schema).
 */
class InvalidReview extends RuntimeException {}
