<?php

namespace Wexample\SymfonyRemoteNominatim\Exception;

use RuntimeException;

/**
 * The service could not answer: unconfigured, unreachable, or refusing.
 * An address it cannot place is no failure: the client answers null.
 */
class NominatimException extends RuntimeException
{
}
