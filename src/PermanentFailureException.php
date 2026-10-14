<?php

namespace Drupal\ash_salesforce;

/**
 * A send Salesforce refused, which retrying won't change.
 */
class PermanentFailureException extends \RuntimeException {}
