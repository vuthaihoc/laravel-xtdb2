<?php

namespace LaravelXtdb\Exceptions;

use LogicException;

/**
 * A Laravel feature XTDB cannot express (row locks, unique constraints, random ordering...).
 */
class UnsupportedFeatureException extends LogicException {}
