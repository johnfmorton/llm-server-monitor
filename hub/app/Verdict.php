<?php

namespace App;

/**
 * The outcome of reviewing one server's day.
 */
enum Verdict: string
{
    case Ok = 'ok';
    case Watch = 'watch';
    case Alert = 'alert';
}
