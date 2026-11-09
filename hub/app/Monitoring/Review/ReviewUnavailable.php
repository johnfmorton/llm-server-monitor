<?php

namespace App\Monitoring\Review;

use RuntimeException;

/**
 * The review can't succeed by trying again: the model declined, or the API
 * rejected the request itself (bad key, bad request, no access to the model).
 * The job records the reason instead of retrying.
 */
class ReviewUnavailable extends RuntimeException {}
